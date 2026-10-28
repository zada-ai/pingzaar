<?php

class MVC_Library_Fcm
{
	public function sendWithToken($token, $data)
	{
		if(!file_exists("system/storage/temporary/firebase.json"))
			return false;
			
		$fcm = (new Kreait\Firebase\Factory)->withServiceAccount("system/storage/temporary/firebase.json");

		$messaging = $fcm->createMessaging();

		try {
			return $messaging->send(Kreait\Firebase\Messaging\CloudMessage::withTarget("token", $token)->withData($data)->withHighestPossiblePriority());
		} catch(Exception $e){
			return false;
		}	
	}

	public function sendWithNotification($token, $title, $body, $image = false)
	{
		if(!file_exists("system/storage/temporary/firebase.json"))
			return false;
			
		$fcm = (new Kreait\Firebase\Factory)->withServiceAccount("system/storage/temporary/firebase.json");

		$messaging = $fcm->createMessaging();

		try {
			if($image):
				$notification = Kreait\Firebase\Messaging\Notification::create($title, $body)
					->withImageUrl($image);
			else:
				$notification = Kreait\Firebase\Messaging\Notification::create($title, $body);
			endif;

			// CRITICAL FIX: Add high priority and Android-specific configuration for reliable wakeup
		$message = Kreait\Firebase\Messaging\CloudMessage::withTarget("token", $token)
			->withNotification($notification)
			->withHighestPossiblePriority()
			->withAndroidConfig(
				Kreait\Firebase\Messaging\AndroidConfig::new()
					->withPriority(Kreait\Firebase\Messaging\AndroidConfig::PRIORITY_HIGH)
					->withNotification(
						Kreait\Firebase\Messaging\AndroidNotification::new()
							->withPriority(Kreait\Firebase\Messaging\AndroidNotification::PRIORITY_MAX)
							->withVisibility(Kreait\Firebase\Messaging\AndroidNotification::VISIBILITY_PUBLIC)
							->withChannelId('fcm_critical_channel')
					)
			);

		return $messaging->send($message);
		} catch(Exception $e){
			return false;
		}
	}

	/**
	 * Send critical wakeup notification with maximum priority for guaranteed device wakeup
	 * Used specifically for urgent system messages that must wake up the device
	 * @param string $token FCM device token
	 * @param string $title Notification title
	 * @param string $body Notification body
	 * @param array $data Additional data payload
	 * @param string|false $image Optional notification image
	 * @return mixed FCM response or false on error
	 */
	public function sendCriticalWakeupNotification($token, $title, $body, $data = [], $image = false)
	{
		if(!file_exists("system/storage/temporary/firebase.json"))
			return false;

		$fcm = (new Kreait\Firebase\Factory)->withServiceAccount("system/storage/temporary/firebase.json");
		$messaging = $fcm->createMessaging();

		try {
			if($image):
				$notification = Kreait\Firebase\Messaging\Notification::create($title, $body)
					->withImageUrl($image);
			else:
				$notification = Kreait\Firebase\Messaging\Notification::create($title, $body);
			endif;

			// MAXIMUM PRIORITY CONFIGURATION for guaranteed device wakeup
			$message = Kreait\Firebase\Messaging\CloudMessage::withTarget("token", $token)
				->withNotification($notification)
				->withData($data)  // Include data payload
				->withHighestPossiblePriority()
				->withAndroidConfig(
					Kreait\Firebase\Messaging\AndroidConfig::new()
						->withPriority(Kreait\Firebase\Messaging\AndroidConfig::PRIORITY_HIGH)
						->withTtl(3600)  // 1 hour TTL for critical messages
						->withCollapseKey('critical_wakeup')  // Collapse key for message grouping
						->withNotification(
							Kreait\Firebase\Messaging\AndroidNotification::new()
								->withPriority(Kreait\Firebase\Messaging\AndroidNotification::PRIORITY_MAX)
								->withVisibility(Kreait\Firebase\Messaging\AndroidNotification::VISIBILITY_PUBLIC)
								->withChannelId('fcm_critical_channel')
								->withTag('critical_wakeup')  // Notification tag for replacement
								->withSound('default')  // Ensure sound plays
						)
						->withData([
							'critical' => 'true',
							'wake_device' => 'true',
							'priority' => 'max'
						])
				)
				->withApnsConfig(
					Kreait\Firebase\Messaging\ApnsConfig::new()
						->withHeaders(['apns-priority' => '10'])  // Maximum iOS priority
						->withPayload([
							'aps' => [
								'alert' => [
									'title' => $title,
									'body' => $body
								],
								'sound' => 'default',
								'badge' => 1,
								'content-available' => 1  // Silent push for background processing
							]
						])
				);

			return $messaging->send($message);
		} catch(Exception $e){
			return false;
		}
	}
}