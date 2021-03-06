<?php namespace StudentAffairsUwm\Shibboleth\Controllers;

use Illuminate\Auth\GenericUser;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\View;

class ShibbolethController extends Controller
{
    // TODO: Can we get rid of this and get it more dynamically?
    private $ctrpath = "\StudentAffairsUwm\\Shibboleth\\Controllers\\ShibbolethController@";

    /**
     * Service Provider
     * @var Shibalike\SP
     */
    private $sp;

    /**
     * Identity Provider
     * @var Shibalike\IdP
     */
    private $idp;

    /**
     * Configuration
     * @var Shibalike\Config
     */
    private $config;

    /**
     * Constructor
     */
    public function __construct(GenericUser $user = null)
    {
        if (config('shibboleth.emulate_idp') == true) {
            $this->config         = new \Shibalike\Config();
            $this->config->idpUrl = 'idp';

            $stateManager = $this->getStateManager();

            $this->sp = new \Shibalike\SP($stateManager, $this->config);
            $this->sp->initLazySession();

            $this->idp = new \Shibalike\IdP($stateManager, $this->getAttrStore(), $this->config);
        }

        $this->user = $user;
    }

    /**
     * Create the session, send the user away to the IDP
     * for authentication.
     */
    public function create()
    {
        if (config('shibboleth.emulate_idp') == true) {
            return Redirect::to(action($this->ctrpath . 'emulateLogin') . '?target=' . action($this->ctrpath . "idpAuthorize"));
        } else {
            return Redirect::to('https://' . Request::server('SERVER_NAME') . ':' . Request::server('SERVER_PORT') . config('shibboleth.idp_login') . '?target=' . action($this->ctrpath . "idpAuthorize"));
        }
    }

    /**
     * Login for users not using the IdP.
     */
    public function localCreate()
    {
        return $this->viewOrRedirect(config('shibboleth.local_login'));
    }

    /**
     * Authorize function for users not using the IdP.
     */
    public function localAuthorize()
    {
        $email    = Input::get(config('shibboleth.local_login_user_field'));
        $password = Input::get(config('shibboleth.local_login_pass_field'));

        if (Auth::attempt(array('email' => $email, 'password' => $password), true)) {
            $userClass  = config('auth.model');
            $groupClass = config('auth.group_model');

            $user = $userClass::where('email', '=', $email)->first();
            if (isset($user->first_name)) {
                Session::put('first', $user->first_name);
            }

            if (isset($user->last_name)) {
                Session::put('last', $user->last_name);
            }

            if (isset($email)) {
                Session::put('email', $user->email);
            }

            if (isset($email)) {
                Session::put('id', User::where('email', '=', $email)->first()->id);
            }
            //TODO: Look at this

            //Group Session Field
            if (isset($email)) {
                try {
                    $group = $groupClass::whereHas('users', function ($q) {
                        $q->where('email', '=', Request::server(config('shibboleth.idp_login_email')));
                    })->first();

                    Session::put('group', $group->name);
                } catch (Exception $e) {
                    // TODO: Remove later after all auth is set up.
                    Session::put('group', 'undefined');
                }
            }

            // Set session to know user is local
            Session::put('auth_type', 'local');
            return $this->viewOrRedirect(config('shibboleth.local_authorized'));
        } else {
            return $this->viewOrRedirect(config('shibboleth.local_unauthorized'));
        }
    }

    /**
     * Setup authorization based on returned server variables
     * from the IdP.
     */
    public function idpAuthorize()
    {
        $email      = $this->getServerVariable(config('shibboleth.idp_login_email'));
        $first_name = $this->getServerVariable(config('shibboleth.idp_login_first'));
        $last_name  = $this->getServerVariable(config('shibboleth.idp_login_last'));

        $userClass  = config('auth.model');
        $groupClass = config('auth.group_model');

        // Attempt to login with the email, if success, update the user model
        // with data from the Shibboleth headers (if present)
        // TODO: This can be simplified a lot
        if (Auth::attempt(array('email' => $email), true)) {
            $user = $userClass::where('email', '=', $email)->first();

            // Update the modal as necessary
            if (isset($first_name)) {
                $user->first_name = $first_name;
            }

            if (isset($last_name)) {
                $user->last_name = $last_name;
            }

            $user->save();

            // Populate the session as needed
            Session::put('first', $user->first_name);
            Session::put('last', $user->last_name);
            Session::put('email', $user->email);
            Session::put('id', $user->id);
            Session::put('auth_type', 'idp');

            // Now let's handle groups
            $groups = $user->groups->toArray();

            // For all groups
            Session::put('groups', $groups);

            // Handle situations where we just want one group info
            if (count($groups) > 0) {
                // For single groups, or the "Primary" group
                Session::put('group_id', $groups[0]['id']);
                Session::put('group_name', $groups[0]['name']);
                // Backwards compatibility
                Session::put('group', $groups[0]['name']);
            } else {
                Session::put('group', 'undefined');
            }

            return $this->viewOrRedirect(config('shibboleth.shibboleth_authenticated'));

        } else {
            //Add user to group and send through auth.
            if (isset($email)) {
                if (config('shibboleth.add_new_users', true)) {
                    $user = $userClass::create(array(
                        'email'      => $email,
                        'type'       => 'shibboleth',
                        'first_name' => $first_name,
                        'last_name'  => $last_name,
                        'enabled'    => 0,
                    ));
                    $group = $groupClass::find(config('shibboleth.shibboleth_group'));

                    $group->users()->save($user);

                    // this is simply brings us back to the session-setting branch directly above
                    if (config('shibboleth.emulate_idp') == true) {
                        return Redirect::to(action($this->ctrpath . 'emulateLogin') . '?target=' . action($this->ctrpath . "idpAuthorize"));
                    } else {
                        return Redirect::to('https://' . Request::server('SERVER_NAME') . ':' . Request::server('SERVER_PORT') . config('shibboleth.idp_login') . '?target=' . action($this->ctrpath . "idpAuthorize"));
                    }
                } else {
                    // Identify that the user was not in our database and will not be created (despite passing IdP)
                    Session::put('auth_type', 'no_user');
                    Session::put('group', 'undefined');

                    return $this->viewOrRedirect(config('shibboleth.shibboleth_unauthorized'));
                }
            }

            return $this->viewOrRedirect(config('shibboleth.login_fail'));
        }
    }

    /**
     * Destroy the current session and log the user out, redirect them to the main route.
     */
    public function destroy()
    {
        Auth::logout();
        Session::flush();

        if (Session::get('auth_type') == 'idp') {
            if (config('shibboleth.emulate_idp') == true) {
                return Redirect::to(action($this->ctrpath . 'emulateLogout'));
            } else {
                return Redirect::to('https://' . Request::server('SERVER_NAME') . config('shibboleth.idp_logout'));
            }
        } else {
            return $this->viewOrRedirect(config('shibboleth.local_logout'));
        }
    }

    /**
     * Emulate a login via Shibalike
     */
    public function emulateLogin()
    {
        $from = (Input::get('target') != null) ? Input::get('target') : $this->getServerVariable('HTTP_REFERER');

        $this->sp->makeAuthRequest($from);
        $this->sp->redirect();
    }

    /**
     * Emulate a logout via Shibalike
     */
    public function emulateLogout()
    {
        $this->sp->logout();
        die('Goodbye, fair user. <a href="' . $this->getServerVariable('HTTP_REFERER') . '">Return from whence you came</a>!');
    }

    /**
     * Emulate the 'authorization' via Shibalike
     */
    public function emulateIdp()
    {
        if (Input::get('username') != null) {
            $username = '';
            if (Input::get('username') === Input::get('password')) {
                $username = Input::get('username');
            }

            $userAttrs = $this->idp->fetchAttrs($username);
            if ($userAttrs) {
                $this->idp->markAsAuthenticated($username);
                $this->idp->redirect();
            } else {
                $error = 'Sorry. You failed to authenticate. <a href="idp" alt="Try Again">Try again</a>';
            }
        }
        ?>

        <html>
            <head>
                <title>Emulated IdP Login</title>
                <style type="text/css">
                    body {
                        font-family: sans-serif;
                    }
                    .title {
                        text-align: center;
                        font-weight: 200;
                        color: grey;
                    }
                    input[type="submit"] {
                        padding: 10px;
                        border: 1px solid #cdcdcd;
                        border-radius: 5px;
                        background-color: #fff;
                        min-width: 100%;
                    }
                    input[type="submit"]:hover {
                        background-color: #cdcdcd;
                        cursor: pointer;
                    }
                </style>
            </head>
            <body>
                <div style="margin: 10px auto; width: 100%; border: 1px solid grey; border-radius: 5px; padding: 10px; max-width: 400px; min-width: 300px;">
                    <h2 class="title">Login to Continue</h2>
                    <form action="" method="post" style="color: grey;">
                        <input type="hidden" name="_token" value="<?php echo csrf_token();?>">
                        <?=(isset($error)) ? ('<p><em>' . $error . '</em></p>') : ''?>
                        <p>
                            <label for="username">Username</label>
                            <input type="text" name="username" id="username" style="width: 100%; padding: 5px; border-radius: 5px; border: 1px solid #cdcdcd;" />
                        </p>
                        <p>
                            <label for="password">Password</label>
                            <input type="password" name="password" id="password" style="width: 100%; padding: 5px; border-radius: 5px; border: 1px solid #cdcdcd;" />
                        </p>
                        <p><input type="submit" value="Login"></p>
                    </form>
                </div>
            </div>
        </html>

        <?php

    }

    /**
     * Function to get an attribute store for Shibalike
     */
    private function getAttrStore()
    {
        return new \Shibalike\Attr\Store\ArrayStore(config('shibboleth.emulate_idp_users'));
    }

    /**
     * Gets a state manager for Shibalike
     */
    private function getStateManager()
    {
        $session = \UserlandSession\SessionBuilder::instance()
            ->setSavePath(sys_get_temp_dir())
            ->setName('SHIBALIKE_BASIC')
            ->build();
        return new \Shibalike\StateManager\UserlandSession($session);
    }

    /**
     * Wrapper function for getting server variables.
     * Since Shibalike injects $_SERVER variables Laravel
     * doesn't pick them up. So depending on if we are
     * using the emulated IdP or a real one, we use the
     * appropriate function.
     */
    private function getServerVariable($variableName)
    {
        if (config('shibboleth.emulate_idp') == true) {
            return isset($_SERVER[$variableName]) ? $_SERVER[$variableName] : null;
        } else {
            $nonRedirect = Request::server($variableName);
            $redirect    = Request::server('REDIRECT_' . $variableName);
            return (!empty($nonRedirect)) ? $nonRedirect : $redirect;
        }
    }

    /*
     * Simple function that allows configuration variables
     * to be either names of views, or redirect routes.
     */
    private function viewOrRedirect($view)
    {
        if (View::exists($view)) {
            return view($view);
        }

        return Redirect::to($view);
    }
}
