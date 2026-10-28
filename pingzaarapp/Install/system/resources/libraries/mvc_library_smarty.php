<?php

use Smarty\Smarty;

class MVC_Library_Smarty extends Smarty
{
    public function __construct()
    {
    	parent::__construct();
        $this->setTemplateDir("templates/")
	        ->setCompileDir("system/storage/smarty/compiled/")
	        ->setConfigDir("system/storage/smarty/configs/")
	        ->setCacheDir("system/storage/smarty/cache/")
            ->addExtension(new SmartyModifierExtension());
    }
}

class SmartyModifierExtension extends \Smarty\Extension\Base 
{
	public function getModifierCallback(string $modifierName) 
    {
		if(is_callable($modifierName)):
			return $modifierName;
        endif;

		return null;
	}
}
