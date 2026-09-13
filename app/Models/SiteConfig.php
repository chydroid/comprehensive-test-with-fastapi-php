<?php
declare(strict_types=1);

namespace App\Models;

use Core\Model;

/** 站点配置（siteconfig）：键值对形式 */
class SiteConfig extends Model
{
    protected string $table = 'siteconfig';
    protected bool $timestamps = false;

    protected array $fillable = ['config_key', 'config_value'];

    /** 读出全部配置为 ['key' => 'value'] 映射 */
    public function allAsMap(): array
    {
        $rows = $this->all('id ASC');
        $map = [];
        foreach ($rows as $r) {
            $map[(string) ($r['config_key'] ?? '')] = (string) ($r['config_value'] ?? '');
        }
        return $map;
    }

    /** 按 key 更新，不存在则插入 */
    public function put(string $key, string $value): void
    {
        $row = $this->firstWhere(['config_key' => $key], 'id ASC');
        if ($row === null) {
            $this->create(['config_key' => $key, 'config_value' => $value]);
            return;
        }
        $this->update((int) $row['id'], ['config_value' => $value]);
    }
}
