<?php namespace DMA\Friends\Components;

use Auth;
use Flash;
use Lang;
use Session;
use Postman;
use Cms\Classes\ComponentBase;
use DMA\Friends\Activities\ActivityCode;
use DMA\Friends\Activities\LikeWorkOfArt;
use DMA\Friends\Classes\Notifications\NotificationMessage;

class ActivityCodeForm extends ComponentBase
{

    public function componentDetails()
    {
        return [
            'name'        => 'Activity Code Form',
            'description' => 'A form for users to enter Activity Codes'
        ];
    }

    public function defineProperties()
    {
        return [
            'title' => [
                'title'     => 'Title',
                'default'   => '',
            ]
        ];
    }

    public function onSubmit()
    {

        $params['code'] = $code = post('activity_code');
        
        $user = Auth::getUser(); 

        // Try to process activity codes first
        $activity = ActivityCode::process($user, $params);

        // If still nothing see if its an assession id
        if (!$activity) {
            $activity = LikeWorkOfArt::process($user, $params);
        }
        
        // Find and run  UserMostRecentBadge component. 
        // NOTE: Do this before call Postman there is a bug when calling App::make twice 
        $mostRecent = $this->controller->findComponentByName('UserMostRecentBadge');
        $mostRecent->onRun();
               
        // Send Flash and kiosk notification
        $typeMessage = ($activity) ? 'successful' : 'error';
        $template = 'activity_code_' . $typeMessage;
        
        Postman::send($template, function(NotificationMessage $notification) use ($user, $code, $activity){

            // Set user in the notification
            $notification->to($user, $user->name);
             
            // Send code and activity just in case we want to use in the template
            $notification->addData(['code' => $code,
                                    'activity' => $activity]);
            
            // Set type of flash
            //$notification->addViewSettings(['type' =>  ( $activity ) ? 'info' : 'error']);
             
            // Determine the content of the message
            $holder = ( $activity ) ? 'activityMessage' : 'activityError';
            $message = Session::pull($holder);
                
            $notification->message($message);
             
        }, ['flash', 'kiosk']);
        
        
        return [
            '#flashMessages'            => $this->renderPartial('@flashMessages'),
            'span.points'               => number_format($user->points),
            'div.most-recent-badge'     => $this->controller->renderComponent('UserMostRecentBadge'),
        ];
        

    }

}
