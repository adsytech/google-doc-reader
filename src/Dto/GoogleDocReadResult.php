<?php

namespace Adsytech\GoogleDocReader\Dto;

final class GoogleDocReadResult
{
    public string $title;
    public string $html;

    public function __construct(
        string $title,
        string $html
    ) {
        $this->title = $title;
        $this->html = $html;
    }
}
