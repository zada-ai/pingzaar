<?php

class MVC_Library_Sanitize
{
	public function array($request, $exclude = [])
	{
		$sanitized = []; 

		foreach ($request as $key => $value):
			if(!is_array($value) && !in_array($key, $exclude)):
				if($this->isEmail($value)):
	        		$sanitized[$key] = trim($this->email($value));
	        	elseif($this->isUrl($value)):
	        		$sanitized[$key] = trim($this->url($value));
	        	else:
	        		$sanitized[$key] = trim($this->string($value));
	        	endif;
        	else:
        		$sanitized[$key] = $value;
        	endif;
		endforeach;

		return $sanitized;
	}

	public function json()
	{
		try {
			$requestData = file_get_contents('php://input');
			$data = json_decode($requestData, true);

			$sanitized = []; 

			foreach ($data as $key => $value):
				if(!is_array($value)):
					if($this->isEmail($value)):
						$sanitized[$key] = trim($this->email($value));
					elseif($this->isUrl($value)):
						$sanitized[$key] = trim($this->url($value));
					else:
						$sanitized[$key] = trim($this->string($value));
					endif;
				else:
					$sanitized[$key] = $value;
				endif;
			endforeach;
		} catch (Exception $e) {
			return [];
		}

		return $sanitized;
	}

	public function normalizeContactsCommas($input)
	{
		return implode(',', array_filter(array_map('trim', preg_split('/\s+/', trim($input)))));
	}

	public function cleanAutoreplyKeywordCommas($input) {
		return implode(',', array_filter(explode(',', trim(preg_replace('/,+/', ',', $input), ','))));
	}

	public function length($requests, $limit = 3, $type = 1)
	{
		if($type < 2):
			if(is_array($requests)):
				foreach($requests as $request):
					if(strlen($request) < ($limit))
						return false;
				endforeach;
			else:
				if(strlen($requests) < ($limit))
					return false;
			endif;

			return true;
		else:
			if(is_array($requests)):
				foreach($requests as $request):
					if(strlen($request) > ($limit))
						return true;
				endforeach;
			else:
				if(strlen($requests) > ($limit))
					return true;
			endif;

			return false;
		endif;
	}

	public function string($string, $strip = false)
	{
	    return $strip ? htmlspecialchars(strip_tags($string)) : htmlspecialchars($string);
	}

	public function email($string)
	{
	    return filter_var($string, FILTER_SANITIZE_EMAIL);
	}

	public function url($string)
	{
	    return filter_var($string, FILTER_SANITIZE_URL);
	}

	public function htmlEncode($string)
	{
	    return htmlspecialchars($string);
	}

	public function htmlDecode($string)
	{
	    return htmlspecialchars_decode($string);
	}

	public function isNumeric($val)
	{
	    return is_numeric($val);
	}

	public function isInt($int)
	{
	    if (filter_var($int, FILTER_VALIDATE_INT) === 0 || !filter_var($int, FILTER_VALIDATE_INT) === false)
	        return true;
	    else
	        return false;
	}

	public function isFloat($float)
	{
		if (filter_var($float, FILTER_VALIDATE_FLOAT))
			return true;
		else
			return false;
	}

	public function isEmail($string)
	{
	    if (!filter_var($string, FILTER_VALIDATE_EMAIL) === false)
	        return true;
	    else
	        return false;
	}

	public function isURL($string)
	{
	    if (!filter_var($string, FILTER_VALIDATE_URL) === false)
	        return true;
	    else
	        return false;
	}
}