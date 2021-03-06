<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * @package auth_oidc
 * @author James McQuillan <james.mcquillan@remote-learner.net>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright (C) 2014 onwards Microsoft Open Technologies, Inc. (http://msopentech.com/)
 */

namespace auth_oidc\loginflow;

/**
 * Login flow for the oauth2 authorization code grant.
 */
class authcode extends \auth_oidc\loginflow\base {
    /**
     * Returns a list of potential IdPs that this authentication plugin supports. Used to provide links on the login page.
     *
     * @param string $wantsurl The relative url fragment the user wants to get to.
     * @return array Array of idps.
     */
    public function loginpage_idp_list($wantsurl) {
        if (empty($this->config->clientid) || empty($this->config->clientsecret)) {
            return [];
        }
        if (empty($this->config->authendpoint) || empty($this->config->tokenendpoint)) {
            return [];
        }

        if (!empty($this->config->customicon)) {
            $icon = new \pix_icon('0/customicon', get_string('pluginname', 'auth_oidc'), 'auth_oidc');
        } else {
            $icon = (!empty($this->config->icon)) ? $this->config->icon : 'auth_oidc:o365';
            $icon = explode(':', $icon);
            if (isset($icon[1])) {
                list($iconcomponent, $iconkey) = $icon;
            } else {
                $iconcomponent = 'auth_oidc';
                $iconkey = 'o365';
            }
            $icon = new \pix_icon($iconkey, get_string('pluginname', 'auth_oidc'), $iconcomponent);
        }

        return [
            [
                'url' => new \moodle_url('/auth/oidc/'),
                'icon' => $icon,
                'name' => $this->config->opname,
            ]
        ];
    }

    /**
     * Handle requests to the redirect URL.
     *
     * @return mixed Determined by loginflow.
     */
    public function handleredirect() {
        $state = optional_param('state', '', PARAM_RAW);
        $promptlogin = (bool)optional_param('promptlogin', 0, PARAM_BOOL);
        $promptaconsent = (bool)optional_param('promptaconsent', 0, PARAM_BOOL);
        if (!empty($state)) {
            // Response from OP.
            $this->handleauthresponse($_REQUEST);
        } else {
            // Initial login request.
            $stateparams = ['forceflow' => 'authcode'];
            $extraparams = [];
            if ($promptaconsent === true) {
                $extraparams = ['prompt' => 'admin_consent'];
            }
            $this->initiateauthrequest($promptlogin, $stateparams, $extraparams);
        }
    }

    /**
     * This is the primary method that is used by the authenticate_user_login() function in moodlelib.php.
     *
     * @param string $username The username (with system magic quotes)
     * @param string $password The password (with system magic quotes)
     * @return bool Authentication success or failure.
     */
    public function user_login($username, $password = null) {
        global $CFG, $DB;

        // Check user exists.
        $userfilters = ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id, 'auth' => 'oidc'];
        $userexists = $DB->record_exists('user', $userfilters);

        // Check token exists.
        $tokenrec = $DB->get_record('auth_oidc_token', ['username' => $username]);
        $code = optional_param('code', null, PARAM_RAW);
        $tokenvalid = (!empty($tokenrec) && !empty($code) && $tokenrec->authcode === $code) ? true : false;
        return ($userexists === true && $tokenvalid === true) ? true : false;
    }

    /**
     * Initiate an authorization request to the configured OP.
     *
     * @param bool $promptlogin Whether to prompt for login or use existing session.
     * @param array $stateparams Parameters to store as state.
     * @param array $extraparams Additional parameters to send with the OIDC request.
     */
    public function initiateauthrequest($promptlogin = false, array $stateparams = array(), array $extraparams = array()) {
        $client = $this->get_oidcclient();
        $client->authrequest($promptlogin, $stateparams, $extraparams);
    }

    /**
     * Handle an authorization request response received from the configured OP.
     *
     * @param array $authparams Received parameters.
     */
    protected function handleauthresponse(array $authparams) {
        global $DB, $CFG, $SESSION, $STATEADDITIONALDATA, $USER;

        if (!isset($authparams['code'])) {
            \auth_oidc\utils::debug('No auth code received.', 'authcode::handleauthresponse', $authparams);
            throw new \moodle_exception('errorauthnoauthcode', 'auth_oidc');
        }

        if (!isset($authparams['state'])) {
            \auth_oidc\utils::debug('No state received.', 'authcode::handleauthresponse', $authparams);
            throw new \moodle_exception('errorauthunknownstate', 'auth_oidc');
        }

        // Validate and expire state.
        $staterec = $DB->get_record('auth_oidc_state', ['state' => $authparams['state']]);
        if (empty($staterec)) {
            throw new \moodle_exception('errorauthunknownstate', 'auth_oidc');
        }
        $orignonce = $staterec->nonce;
        $additionaldata = [];
        if (!empty($staterec->additionaldata)) {
            $additionaldata = @unserialize($staterec->additionaldata);
            if (!is_array($additionaldata)) {
                $additionaldata = [];
            }
        }
        $STATEADDITIONALDATA = $additionaldata;
        $DB->delete_records('auth_oidc_state', ['id' => $staterec->id]);

        // Get token from auth code.
        $client = $this->get_oidcclient();
        $tokenparams = $client->tokenrequest($authparams['code']);
        if (!isset($tokenparams['id_token'])) {
            throw new \moodle_exception('errorauthnoidtoken', 'auth_oidc');
        }

        // Decode and verify idtoken.
        list($oidcuniqid, $idtoken) = $this->process_idtoken($tokenparams['id_token'], $orignonce);

        // Check restrictions.
        $passed = $this->checkrestrictions($idtoken);
        if ($passed !== true) {
            $errstr = 'User prevented from logging in due to restrictions.';
            \auth_oidc\utils::debug($errstr, 'handleauthresponse', $idtoken);
            throw new \moodle_exception('errorrestricted', 'auth_oidc');
        }

        // This is for setting the system API user.
        if (isset($SESSION->auth_oidc_justevent)) {
            unset($SESSION->auth_oidc_justevent);
            $eventdata = ['other' => ['authparams' => $authparams, 'tokenparams' => $tokenparams]];
            $event = \auth_oidc\event\user_authed::create($eventdata);
            $event->trigger();
            return true;
        }

        // Check if OIDC user is already migrated.
        $tokenrec = $DB->get_record('auth_oidc_token', ['oidcuniqid' => $oidcuniqid]);
        if (isloggedin() === true && (empty($tokenrec) || (isset($USER->auth) && $USER->auth !== 'oidc'))) {
            // If the user is already logged in we can treat this as a "migration" - a user switching to OIDC.
            $connectiononly = false;
            if (isset($SESSION->auth_oidc_connectiononly)) {
                $connectiononly = true;
                unset($SESSION->auth_oidc_connectiononly);
            }
            if (isset($STATEADDITIONALDATA['connectiononly']) && $STATEADDITIONALDATA['connectiononly'] === true) {
                $connectiononly = true;
            }
            $this->handlemigration($oidcuniqid, $authparams, $tokenparams, $idtoken, $connectiononly);
            $redirect = (!empty($additionaldata['redirect'])) ? $additionaldata['redirect'] : '/auth/oidc/ucp.php';
            redirect(new \moodle_url($redirect));
        } else {
            // Otherwise it's a user logging in normally with OIDC.
            $this->handlelogin($oidcuniqid, $authparams, $tokenparams, $idtoken);
            redirect(core_login_get_return_url());
        }
    }

    /**
     * Handle a user migration event.
     *
     * @param string $oidcuniqid A unique identifier for the user.
     * @param array $authparams Paramteres receieved from the auth request.
     * @param array $tokenparams Parameters received from the token request.
     * @param \auth_oidc\jwt $idtoken A JWT object representing the received id_token.
     * @param bool $connectiononly Whether to just connect the user (true), or to connect and change login method (false).
     */
    protected function handlemigration($oidcuniqid, $authparams, $tokenparams, $idtoken, $connectiononly = false) {
        global $USER, $DB, $CFG;

        // Check if OIDC user is already connected to a Moodle user.
        $tokenrec = $DB->get_record('auth_oidc_token', ['oidcuniqid' => $oidcuniqid]);
        if (!empty($tokenrec)) {
            $existinguserparams = ['username' => $tokenrec->username, 'mnethostid' => $CFG->mnet_localhost_id];
            $existinguser = $DB->get_record('user', $existinguserparams);
            if (empty($existinguser)) {
                $DB->delete_records('auth_oidc_token', ['id' => $tokenrec->id]);
            } else {
                if ($USER->username === $tokenrec->username) {
                    // Already connected to current user.
                    if ($connectiononly !== true && $USER->auth !== 'oidc') {
                        // Update auth plugin.
                        $DB->update_record('user', (object)['id' => $USER->id, 'auth' => 'oidc']);
                        $USER = $DB->get_record('user', ['id' => $USER->id]);
                        $USER->auth = 'oidc';
                    }
                    $this->updatetoken($tokenrec->id, $authparams, $tokenparams);
                    return true;
                } else {
                    // OIDC user connected to user that is not us. Can't continue.
                    throw new \moodle_exception('errorauthuserconnectedtodifferent', 'auth_oidc');
                }
            }
        }

        // Check if Moodle user is already connected to an OIDC user.
        $tokenrec = $DB->get_record('auth_oidc_token', ['username' => $USER->username]);
        if (!empty($tokenrec)) {
            if ($tokenrec->oidcuniqid === $oidcuniqid) {
                // Already connected to current user.
                if ($connectiononly !== true && $USER->auth !== 'oidc') {
                    // Update auth plugin.
                    $DB->update_record('user', (object)['id' => $USER->id, 'auth' => 'oidc']);
                    $USER = $DB->get_record('user', ['id' => $USER->id]);
                    $USER->auth = 'oidc';
                }
                $this->updatetoken($tokenrec->id, $authparams, $tokenparams);
                return true;
            } else {
                throw new \moodle_exception('errorauthuseralreadyconnected', 'auth_oidc');
            }
        }

        // get userinfo
        $client = $this->get_oidcclient();
        $userinfoparams = $client->userinforequest($tokenparams['access_token']);
        // Create token data.
        //$tokenrec = $this->createtoken($oidcuniqid, $USER->username, $authparams, $tokenparams, $idtoken);
        $tokenrec = $this->createtoken($oidcuniqid, $userinfoparams['username'], $authparams, $tokenparams, $idtoken);

        $eventdata = [
            'objectid' => $USER->id,
            'userid' => $USER->id,
            'other' => ['username' => $USER->username, 'userid' => $USER->id]
        ];
        $event = \auth_oidc\event\user_connected::create($eventdata);
        $event->trigger();

        // Switch auth method, if requested.
        if ($connectiononly !== true) {
            if ($USER->auth !== 'oidc') {
                $DB->delete_records('auth_oidc_prevlogin', ['userid' => $USER->id]);
                $userrec = $DB->get_record('user', ['id' => $USER->id]);
                if (!empty($userrec)) {
                    $prevloginrec = [
                        'userid' => $userrec->id,
                        'method' => $userrec->auth,
                        'password' => $userrec->password,
                    ];
                    $DB->insert_record('auth_oidc_prevlogin', $prevloginrec);
                }
            }
            $DB->update_record('user', (object)['id' => $USER->id, 'auth' => 'oidc']);
            $USER = $DB->get_record('user', ['id' => $USER->id]);
            $USER->auth = 'oidc';
        }

        return true;
    }

    /**
     * Determines whether the given Azure AD UPN is already matched to a Moodle user (and has not been completed).
     *
     * @return false|stdClass Either the matched Moodle user record, or false if not matched.
     */
    protected function check_for_matched($aadupn) {
        global $DB;
        $dbman = $DB->get_manager();
        if ($dbman->table_exists('local_o365_connections')) {
            $match = $DB->get_record('local_o365_connections', ['aadupn' => $aadupn]);
            if (!empty($match) && \local_o365\utils::is_o365_connected($match->muserid) !== true) {
                return $DB->get_record('user', ['id' => $match->muserid]);
            }
        }
        return false;
    }

    /**
     * Handle a login event.
     *
     * @param string $oidcuniqid A unique identifier for the user.
     * @param array $authparams Parameters receieved from the auth request.
     * @param array $tokenparams Parameters received from the token request.
     * @param \auth_oidc\jwt $idtoken A JWT object representing the received id_token.
     */
    protected function handlelogin($oidcuniqid, $authparams, $tokenparams, $idtoken) {
        global $DB, $CFG;

        $tokenrec = $DB->get_record('auth_oidc_token', ['oidcuniqid' => $oidcuniqid]);
        if (!empty($tokenrec)) {
            $username = $tokenrec->username;
            $this->updatetoken($tokenrec->id, $authparams, $tokenparams);
        } else {
            // get userinfo
            $client = $this->get_oidcclient();
            $userinfoparams = $client->userinforequest($tokenparams['access_token']);
            // Use 'upn' if available for username (Azure-specific), or fall back to lower-case oidcuniqid.
            $username = $idtoken->claim('upn');
            if (empty($username)) {
                //$username = $oidcuniqid;
                $username = $userinfoparams['username'];
            }
            $matchedwith = $this->check_for_matched($username);
            if (!empty($matchedwith)) {
                $matchedwith->aadupn = $username;
                throw new \moodle_exception('errorusermatched', 'local_o365', null, $matchedwith);
            }
            //$username = trim(\core_text::strtolower($username));
            //$tokenrec = $this->createtoken($oidcuniqid, $username, $authparams, $tokenparams, $idtoken);
            $tokenrec = $this->createtoken($oidcuniqid, $userinfoparams['username'], $authparams, $tokenparams, $idtoken);
        }

        $existinguserparams = ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id];
        if ($DB->record_exists('user', $existinguserparams) !== true) {
            // User does not exist. Create user if site allows, otherwise fail.
            if (empty($CFG->authpreventaccountcreation)) {
                $user = create_user_record($username, null, 'oidc');
            } else {
                // Trigger login failed event.
                $failurereason = AUTH_LOGIN_NOUSER;
                $eventdata = ['other' => ['username' => $username, 'reason' => $failurereason]];
                $event = \core\event\user_login_failed::create($eventdata);
                $event->trigger();
                throw new \moodle_exception('errorauthloginfailednouser', 'auth_oidc');
            }
        }

        $user = authenticate_user_login($username, null, true);
        if (empty($user)) {
            throw new \moodle_exception('errorauthloginfailednouser', 'auth_oidc');
        }

        complete_user_login($user);
        return true;
    }
}
