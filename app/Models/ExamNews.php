<?php
declare(strict_types=1);

namespace App\Models;

use Core\Model;

/** 新闻公告（examnews） */
class ExamNews extends Model
{
    protected string $table = 'examnews';
    protected bool $timestamps = false;

    protected array $fillable = ['news_title', 'news_info', 'news_writer', 'news_time'];
}
