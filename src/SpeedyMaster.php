<?php

namespace Speedy;

/**
 * SpeedMaster主进程
 *
 * Class SpeedyMaster
 * @package Speedy
 */
class SpeedyMaster
{
    /**
     * 系统平台： linux or macos
     */
    const OS_UNIX_LIKE = 1;

    /**
     * 系统平台： windows
     */
    const OS_WINDOWS = 2;

    /**
     * fid对应的类型
     */
    const FID_TYPE = [
        'socket' => 0140000,
        'link' => 0120000,
        'file' => 0100000,
        'block' => 0060000,
        'dir' => 0040000,
        'char' => 0020000,
        'fifo' => 0010000
    ];


    /**
     * @var int 当前运行的平台
     */
    public static int $os = self::OS_UNIX_LIKE;

    /**
     * @var resource 程序标准输出
     */
    public static mixed $outputStream = null;

    /**
     * @var bool 是否支持装饰（文件就不支持，终端可以）
     */
    public static bool $outputDecorated = false;

    /**
     * @var string 版本号
     */
    public string $version = "v1.0.0";


    public static function run()
    {
        static::checkEnv();
    }

    /**
     * 检测程序运行的环境
     */
    public static function checkEnv(): void
    {
        if (php_sapi_name() !== 'cli') {
            exit("speedy-master only can run on cli\r\n");
        }
        if (DIRECTORY_SEPARATOR === "\\") {
            static::$os = static::OS_WINDOWS;
        }
    }

    public static function init(): void
    {

    }

    /**
     * 标准输出信息
     *
     * @param string $msg
     * @param bool $decorated
     */
    public static function echoOut(string $msg, bool $decorated = false): void
    {
        static::__restOutputStream();
        $stream = static::$outputStream;
        if (!$stream) {
            return;
        }
        if (!$decorated) {
            $line = $white = $green = $end = "";
            if (static::$outputDecorated) {// 需要装饰
                $line = "\033[1A\n\033[K";
                $white = "\033[47;30m";
                $green = "\033[32;40m";
                $end = "\033[0m";
            }
            $msg = str_replace(['<l>', '<w>', '<g>'], [$line, $white, $green], $msg);
            $msg = str_replace(['</l>', '</w>', '</g>', '</e>'], $end, $msg);
        }

        fwrite($stream, $msg);
        fflush($stream);
    }

    /**
     * 重置标准输出
     *
     * @param mixed|null $stream
     */
    private static function __restOutputStream(mixed $stream = null): void
    {
        if (!$stream) {
            $stream = static::$outputStream ?? STDOUT;
        }
        if (!$stream || !is_resource($stream) || get_resource_type($stream) !== 'stream') {
            return;
        }

        $stat = fstat($stream);
        if ($stat === false) {
            return;
        }

        if (static::FID_TYPE["file"] === $stat['mode'] & 0100000) {
            static::$outputDecorated = false;
        } elseif (static::$os === static::OS_UNIX_LIKE && function_exists("posix_isatty") && posix_isatty($stream)) {
            // 判断是不是unix-like平台，并且stream是一个可以交互的终端才装饰
            static::$outputDecorated = true;
        }
        static::$outputStream = $stream;
    }
}