<?php

class MVC_Library_Spintax
{
    public function process($spintax) {
        while (preg_match('/\{([^{}]*)\}/', $spintax, $matches)) {
            $spintax = preg_replace_callback(
                '/\{([^{}]*)\}/',
                function ($matches) {
                    $choices = explode('|', $matches[1]);
                    return $choices[array_rand($choices)];
                },
                $spintax,
                1
            );
        }
        return $spintax;
    }
}