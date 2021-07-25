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
     * 版本号
     */
    const VERSION = "V1.0.0";

    /**
     * 系统平台： linux or macos
     */
    const OS_UNIX_LIKE = 1;

    /**
     * 系统平台： windows
     */
    const OS_WINDOWS = 2;

    /**
     * 进程状态：正在启动
     */
    const STATUS_STARING = 1;

    /**
     * 进程状态：正在运行
     */
    const STATUS_RUNNING = 2;

    /**
     * 进程状态：正在重启
     */
    const STATUS_RELOADING = 3;

    /**
     * 进程状态：已停止
     */
    const STTAUS_SHUTDOWN = 4;
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
     * @var string 入口运行文件
     */
    public static string $startFile;

    /**
     * @var string pid文件存放位置
     */
    public static string $pidFile;

    /**
     * @var string 日志文件存放位置
     */
    public static string $logFile;

    /**
     * @var int 进程当前状态
     */
    public static int $status;

    /**
     * @var string 进程标题前缀
     */
    public static string $processPrefix;
    /**
     * @var array 进程全局状态变量
     */
    public static array $statistics = [
        'start_timestamp' => 0,
        'work_exit_info' => []
    ];

    /**
     * @var string 监控文件
     */
    public static string $statusMonitorFile;

    public static function run()
    {
        static::checkEnv();
        static::init();
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
        static::checkEnv();
        static::setErrorHandler();
        static::initAllFile();
        static::setProcessTitle(self::$processPrefix . "_master process start file " . self::$startFile);
        static::initMonitor();

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
        } elseif (!static::$outputDecorated) {
            return;
        }

        fwrite($stream, $msg);
        fflush($stream);
    }

    /**
     * 设置php错误回调
     */
    public static function setErrorHandler(): void
    {
        set_error_handler(
            function (int $code, string $errorStr, string $file, string $line) {
                $out = sprintf(
                    "erorCode is <g>%d</g>, error desc is <g>%s</g>, file in <g>%s</g>, line <g>%d</g>\r\n",
                    $code,
                    $errorStr,
                    $file,
                    $line,
                );
                static::echoOut($out);
            }
        );
    }

    /**
     * 初始化文件
     */
    public static function initAllFile(): void
    {
        $traceInfo = debug_backtrace();
        static::$startFile = $traceInfo[count($traceInfo) - 1]['file'];

        if (empty(static::$logFile)) {
            static::$logFile = dirname(static::$startFile) . DIRECTORY_SEPARATOR . "logSpeedy.log";
        }
        if (!is_file(static::$logFile)) {
            touch(static::$logFile);
            chmod(static::$logFile, 0622);
        }

        if (empty(static::$pidFile)) {
            $nameUnique = str_replace("/", "_", self::$startFile);
            static::$pidFile = dirname(static::$startFile) . DIRECTORY_SEPARATOR . $nameUnique . ".pid";
        }
    }

    /**
     * 初始化进程状态和监控
     */
    public static function initMonitor(): void
    {
        static::$status = static::STATUS_STARING;
        static::$statistics['start_timestamp'] = time();
        static::$statusMonitorFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "speedy.status";
    }

    /**
     * 设置进程标题
     *
     * @param string $title
     */
    public static function setProcessTitle(string $title): void
    {
        set_error_handler(
            function () {
            }
        );
        if (function_exists('cli_set_process_title')) {
            cli_set_process_title($title);
        }

        restore_error_handler();
    }

    public static function initId(): void
    {
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