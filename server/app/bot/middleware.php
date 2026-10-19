<?php
// 应用中间件：多应用模式下语言包需在应用初始化之后加载
return [
    \think\middleware\LoadLangPack::class,
];
