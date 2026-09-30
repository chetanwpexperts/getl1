<?php

namespace App\Support;

final class Md
{
    /**
     * Escape user-entered text placed inside Markdown emails, so nobody can inject links,
     * images or table breaks into a GetL1 email (e.g. an item named "[Pay here](http://…)").
     * Blade's {{ }} still HTML-escapes the result.
     */
    public static function escape(?string $text): string
    {
        $text = str_replace(["\r", "\n"], ' ', (string) $text);

        return preg_replace('/([\\\\`*_{}\[\]()#+\-!|<>~])/', '\\\\$1', $text);
    }
}
