<?php

namespace App\Enums;

enum PostType: string
{
    case News = 'news';
    case Review = 'review';
    case Explainer = 'explainer';
    case ListPost = 'list';
}