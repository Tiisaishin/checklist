<?php
namespace app\common\detector;

class DetectResult
{
    public function __construct(
        public ?string $expireTime = null, // UTC Y-m-d H:i:s
        public string $provider = '',
        public array $meta = [],
    ) {
    }
}
