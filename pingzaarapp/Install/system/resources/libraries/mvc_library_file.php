<?php

class MVC_Library_File
{
	public function put($file, $content, $flags = 0)
	{
		// Create the parent directory if it is missing. Directories that ship empty
		// do not survive every transport — FTP clients and `unzip -D` both drop them
		// — and file_put_contents() will not create one, so without this a write
		// silently fails and the caller reports success.
		$directory = dirname($file);

		if($directory !== "" && !is_dir($directory))
			@mkdir($directory, 0755, true);

		return file_put_contents($file, $content, $flags);
	}

	public function get($file)
	{
		return file_get_contents($file);
	}

	public function delete($file)
	{
		if(file_exists($file))
			return unlink($file);
		else
			return false;
	}

	public function exists($file)
	{
		return file_exists($file);
	}

	public function move($old, $new)
	{
		return rename($old, $new);
	}

	public function mkdir($path)
	{
		if(!is_dir($path)):
			mkdir($path, 0775, true);
		else:
			return false;
		endif;
	}
}