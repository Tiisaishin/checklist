<?php
namespace app\common\model;

use think\Model;

abstract class BaseModel extends Model
{
    protected $autoWriteTimestamp = 'datetime';
}
