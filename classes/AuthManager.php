<?php namespace DMA\Friends\Classes;

use Auth;
use Event;
use Validator;
use Exception;
use ValidationException;
use RainLab\User\Models\Settings as UserSettings;
use RainLab\User\Models\User;
use DMA\Friends\Models\Usermeta;

/**
 * Manage custom authentication for friends
 *
 * @package DMA\Friends\Classes
 * @author Kristen Arnold, Carlos Arroyo
 */
class AuthManager
{

    /**
     * Authenticate a user by either username, email, or member id
     *
     * @param array $data
     * An array of attributes to authenticate a user minimially requires the following
     * - login
     *      Provide either the email address or username to authenticate.  This 
     *      setting is configured in the administrative backend
     * - password
     *      A password
     * - no_password
     *      If true password authentication will be bypassed.  Use with caution
     *      as this can be a security breach if used incorrectly.
     *
     * @param array $rules
     * A set of validation rules to validate against
     * see http://laravel.com/docs/5.1/validation
     *
     * @return boolean
     * returns true if the user is authenticated
     */
    public static function auth($data, $rules = [])
    {
        $user = false;

        // Fire prelogin event before we start processing the user
        Event::fire('auth.prelogin', [$data, $rules]);

        if (!isset($data['no_password'])) {
            $data['no_password'] = false;
        }

        if (!$data['no_password']) {
            if (!isset($rules['password'])) {
                $rules['password'] = 'required|min:4';
            }

            if (!isset($rules['login'])) {
                $rules['login'] = 'required|between:4,64';
            }

            /*
             * Validate user credentials
             */
            $validation = Validator::make($data, $rules);
            if ($validation->fails()) {
                throw new ValidationException($validation);
            }

        }

        // Attempt to lookup by member_id
        if (!$user) {
            $user = self::isMember($data['login']);
        }

        // Attempt to look up barcode
        if (!$user) {
            $user = self::isBarcode($data['login']);
        }
   
        try {        

            if ($user && $data['no_password']) {
                Auth::login($user);
            } else {
                $user = self::loginUser($user, $data);
            }
        } catch(Exception $e) {
            $user = Event::fire('auth.invalidLogin', [$data, $rules]);

            if (!$user) throw new Exception($e);
        }

        if ($user) {
            Event::fire('auth.login', $user);
            return true;
        }

        return false;
    }

    /**
     * Lookup user by member id
     *
     * @param string $id
     * An id to lookup by member id
     *
     * @return User $user
     * A RainLab\User\Model\User object
     */
    private static function isMember($id)
    {
        $usermeta = Usermeta::with('user')->where('current_member_number', '=', $id)->first();
        if (isset($usermeta->user) && !empty($usermeta->user)) {
            $user = $usermeta->user;

            return $user;
        } 

        return false;
    }

    /**
     * Lookup user by barcode id
     *
     * @param string $id
     * An id to lookup by barcode id
     *
     * @return User $user
     * A RainLab\User\Model\User object
     */
    private static function isBarcode($id)
    {
        $user = User::where('barcode_id', $id)->first();

        if ($user) {
            return $user;
        }

        return false;
    }

    /**
     * Attempt to authenticate a user with a password
     *
     * @param User $user
     * A RainLab\User\Model\User object
     * 
     * @param array $data
     * An array of paramaters for authenticating.
     *
     * @return User $user
     * A RainLab\User\Model\User object of the authenticated user
     */
    private static function loginUser($user, $data)
    {
        $loginAttribute = UserSettings::get('login_attribute', UserSettings::LOGIN_EMAIL);

        if ($user) {
            if ($loginAttribute == UserSettings::LOGIN_USERNAME) {
                $data['login'] = $user->username;
            } else {
                $data['login'] = $user->email;
            }

        }

        /*  
         * Authenticate user
         */
        $user = Auth::authenticate([
            'login'     => array_get($data, 'login'),
            'password'  => array_get($data, 'password')
        ], true);
 
        return $user;
    }
}