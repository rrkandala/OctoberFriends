<?php
namespace DMA\Friends\Classes;

use DMA\Friends\Classes\BadgeManager;
use DMA\Friends\Models\Activity;
use DMA\Friends\Classes\UserExtend;
use DMA\Friends\Models\Settings;
use RainLab\User\Models\User;
use Event;
use Lang;
use Str;
use Config;
use FriendsLog;
use DateTime;
use Carbon\Carbon;

interface ActivityTypeBaseInterface {
    public function details();
    public function getConfig();
    public function getFormDefaultValues($model);
    public function saveData($model, $values);
    public static function process(User $user, $params);
    public static function canComplete(Activity $activity, User $user);
}

class ActivityTypeBase implements ActivityTypeBaseInterface
{ 
    /**
     * @var string Name of the form configuration
     */
    public $fieldConfig = 'fields.yaml';

    /** 
     * @var string Specifies the component directory name.
     */
    protected $dirName;

    public function __construct()
    {
        $className = Str::normalizeClassName(get_called_class());
        $this->dirName = strtolower(str_replace('\\', '/', $className));
    }

    /**
     * Register details about your activity.
     * 
     * @return array 
     * An array of options
     * - name: The name of the activity type.  
     *   This will be used in the form drop down when users configure an activity
     * - description: (optional) An optional description of the activity type
     */
    public function details() {}

    /**
     * Return the path to the yaml configuration for additional form fields
     *
     * @return string 
     * YAML config path
     */
    public function getConfig()
    {
        return $this->dirName . '/' . $this->fieldConfig;
    }

    /**
     * If additional fields are configured then implement to
     * tell the form how to get the default values
     *
     * @param object $model
     * The activity model
     *
     * @return array
     * An array with matching key/value pairs for a
     * set of custom fields
     */
    public function getFormDefaultValues($model) {}

    /**
     * Save additional data for an activity type. By default
     * attempt to save any additional field directly to model
     * attributes
     *
     * @param object $model
     * An activity model
     *
     * @param array $values
     * an array of values
     */
    public function saveData($model, $values) {
        foreach ($values as $key => $val) {
            $model->{$key} = $val;
        }
    }

    /** 
     * Process and determine if an award can be issued
     * based on a provided activity code
     *
     * @param object $user
     * A user model for which the activity should act upon
     * 
     * @param array $params
     * An array of parameters for validating activities 
     *
     * @return boolean
     * returns true if the process was successful
     */
    public static function process(User $user, $params)
    {
        $activity = $params['activity'];

        if (self::canComplete($activity, $user)) {
            $userExtend = new UserExtend($user);
            $userExtend->addPoints($activity->points);

            if ($user->activities()->save($activity)) {

                Event::fire('friends.activityCompleted', [ $user, $activity ]); 

                // log an entry to the activity log
                FriendsLog::activity([
                    'user'          => $user,
                    'object'        => $activity,
                ]); 

                // Hand everything off to the badges
                BadgeManager::applyActivityToBadges($user, $activity);

                return $activity;
            }
        }

        return false;
    }

   /** 
     * Determine if an activity is capable of being completed
     *
     * @param Activity
     * An activity model
     *
     * @return boolean
     * returns true if an activity can be completed by the user
     */
    public static function canComplete(Activity $activity, User $user)
    {   
        if (!$activity->isActive()) return false;

        if ($activity->activity_lockout && $user->activities()->first()) {
            $time       = Carbon::now();
            $lastTime   = $user->activities()->first()->pivot->created_at;
            $lastTime->addMinutes($activity->activity_lockout);

            if ($lastTime->gt($time)) return false;
        }

        //TODO: need a better way to return errors on why the activity couldnt complete

        switch ($activity->time_restriction) {
            case Activity::TIME_RESTRICT_NONE:
                return true;
            case Activity::TIME_RESTRICT_HOURS:
                if ($activity->time_restriction_data) {

                    $now    = Carbon::now();
                    $start  = self::convertTime($activity->time_restriction_data['start_time']);
                    $end    = self::convertTime($activity->time_restriction_data['end_time']);

                    $start_time = Carbon::now();
                    $start_time->setTime($start['hour'], $start['minutes']);
                    $end_time   = Carbon::now();
                    $end_time->setTime($end['hour'], $start['minutes']);
                    $day        = date('w');

                    if ($activity->time_restriction_date['days'][$day] !== false
                        && $now->gte($start_time) && $now->lte($end_time)) {

                        return true;
                    } 
                }

                break;
            case Activity::TIME_RESTRICT_DAYS: 
                $now = new DateTime('now');
                if ($now >= $activity->date_begin 
                    && $now <= $activity->date_end) return true;

                break;
        } 

        return false;
    } 

    /**
     * Convert a time string into an array
     *
     * @param string $timeString
     * A time string ie (03:00pm)
     *
     * @return array
     * an array of the hour and minutes in military time
     */
    protected static function convertTime($timeString)
    {
        list($hour, $minutes) = explode(":", $timeString);
        $meridiem = substr($minutes, 2, 3);
        $minutes = substr($minutes, 0, 1);


        if (strtolower($meridiem) == 'pm' || ($hour == '12' && strtolower($meridiem) == 'am')) {
            $hour += 12;
        }

        return [
            'hour'      => $hour,
            'minutes'   => $minutes,
        ];
    }

}