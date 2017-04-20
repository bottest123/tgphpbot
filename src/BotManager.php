<?php declare(strict_types=1);
/**
 * This file is part of the TelegramBotManager package.
 *
 * (c) Armando Lüscher <armando@noplanman.ch>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace NPM\TelegramBotManager;

use Allty\Utils\IpTools;
use Longman\TelegramBot\Entities;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\TelegramLog;
use NPM\TelegramBotManager\Exception\InvalidAccessException;
use NPM\TelegramBotManager\Exception\InvalidWebhookException;

class BotManager
{
    /**
     * @var string Telegram post servers IP range
     */
    const TELEGRAM_IP_RANGE = '149.154.167.197-149.154.167.233';

    /**
     * @var string The output for testing, instead of echoing
     */
    private $output = '';

    /**
     * @var \Longman\TelegramBot\Telegram
     */
    private $telegram;

    /**
     * @var \NPM\TelegramBotManager\Params Object that manages the parameters.
     */
    private $params;

    /**
     * @var \NPM\TelegramBotManager\Action Object that contains the current action.
     */
    private $action;

    /**
     * BotManager constructor.
     *
     * @param array $params
     *
     * @throws \NPM\TelegramBotManager\Exception\InvalidParamsException
     * @throws \NPM\TelegramBotManager\Exception\InvalidActionException
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    public function __construct(array $params)
    {
        $this->params = new Params($params);
        $this->action = new Action($this->params->getScriptParam('a'));

        // Set up a new Telegram instance.
        $this->telegram = new Telegram(
            $this->params->getBotParam('api_key'),
            $this->params->getBotParam('bot_username')
        );
    }

    /**
     * Check if we're busy running the PHPUnit tests.
     *
     * @return bool
     */
    public static function inTest(): bool
    {
        return defined('PHPUNIT_TEST') && PHPUNIT_TEST === true;
    }

    /**
     * Return the Telegram object.
     *
     * @return \Longman\TelegramBot\Telegram
     */
    public function getTelegram(): Telegram
    {
        return $this->telegram;
    }

    /**
     * Get the Params object.
     *
     * @return \NPM\TelegramBotManager\Params
     */
    public function getParams(): Params
    {
        return $this->params;
    }

    /**
     * Get the Action object.
     *
     * @return \NPM\TelegramBotManager\Action
     */
    public function getAction(): Action
    {
        return $this->action;
    }

    /**
     * Run this thing in all its glory!
     *
     * @return \NPM\TelegramBotManager\BotManager
     * @throws \Longman\TelegramBot\Exception\TelegramException
     * @throws \NPM\TelegramBotManager\Exception\InvalidAccessException
     * @throws \NPM\TelegramBotManager\Exception\InvalidWebhookException
     * @throws \Exception
     */
    public function run(): self
    {
        // Initialise logging.
        $this->initLogging();

        // Make sure this is a valid call.
        $this->validateSecret();

        if ($this->action->isAction(['set', 'unset', 'reset'])) {
            $this->validateAndSetWebhook();
        } elseif ($this->action->isAction('handle')) {
            // Set any extras.
            $this->setBotExtras();
            $this->handleRequest();
        }

        return $this;
    }

    /**
     * Initialise all loggers.
     *
     * @return \NPM\TelegramBotManager\BotManager
     * @throws \Exception
     */
    public function initLogging(): self
    {
        $logging = $this->params->getBotParam('logging');
        if (is_array($logging)) {
            /** @var array $logging */
            foreach ($logging as $logger => $logfile) {
                ('debug' === $logger) && TelegramLog::initDebugLog($logfile);
                ('error' === $logger) && TelegramLog::initErrorLog($logfile);
                ('update' === $logger) && TelegramLog::initUpdateLog($logfile);
            }
        }

        return $this;
    }

    /**
     * Make sure the passed secret is valid.
     *
     * @param bool $force Force validation, even on CLI.
     *
     * @return \NPM\TelegramBotManager\BotManager
     * @throws \NPM\TelegramBotManager\Exception\InvalidAccessException
     */
    public function validateSecret(bool $force = false): self
    {
        // If we're running from CLI, secret isn't necessary.
        if ($force || 'cli' !== PHP_SAPI) {
            $secret     = $this->params->getBotParam('secret');
            $secret_get = $this->params->getScriptParam('s');
            if ($secret_get !== $secret) {
                throw new InvalidAccessException('Invalid access');
            }
        }

        return $this;
    }

    /**
     * Make sure the webhook is valid and perform the requested webhook operation.
     *
     * @return \NPM\TelegramBotManager\BotManager
     * @throws \Longman\TelegramBot\Exception\TelegramException
     * @throws \NPM\TelegramBotManager\Exception\InvalidWebhookException
     */
    public function validateAndSetWebhook(): self
    {
        $webhook = $this->params->getBotParam('webhook');
        if (empty($webhook['url'] ?? null) && $this->action->isAction(['set', 'reset'])) {
            throw new InvalidWebhookException('Invalid webhook');
        }

        if ($this->action->isAction(['unset', 'reset'])) {
            $this->handleOutput($this->telegram->deleteWebhook()->getDescription() . PHP_EOL);
            // When resetting the webhook, sleep for a bit to prevent too many requests.
            $this->action->isAction('reset') && sleep(1);
        }

        if ($this->action->isAction(['set', 'reset'])) {
            $webhook_params = array_filter([
                'certificate'     => $webhook['certificate'] ?? null,
                'max_connections' => $webhook['max_connections'] ?? null,
                'allowed_updates' => $webhook['allowed_updates'] ?? null,
            ]);

            $this->handleOutput(
                $this->telegram->setWebhook(
                    $webhook['url'] . '?a=handle&s=' . $this->params->getBotParam('secret'),
                    $webhook_params
                )->getDescription() . PHP_EOL
            );
        }

        return $this;
    }

    /**
     * Save the test output and echo it if we're not in a test.
     *
     * @param string $output
     *
     * @return \NPM\TelegramBotManager\BotManager
     */
    private function handleOutput(string $output): self
    {
        $this->output .= $output;

        if (!self::inTest()) {
            echo $output;
        }

        return $this;
    }

    /**
     * Set any extra bot features that have been assigned on construction.
     *
     * @return \NPM\TelegramBotManager\BotManager
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    public function setBotExtras(): self
    {
        $this->setBotExtrasTelegram();
        $this->setBotExtrasRequest();

        return $this;
    }

    /**
     * Set extra bot parameters for Telegram object.
     *
     * @return \NPM\TelegramBotManager\BotManager
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    protected function setBotExtrasTelegram(): self
    {
        $simple_extras = [
            'admins'         => 'enableAdmins',
            'mysql'          => 'enableMySql',
            'commands.paths' => 'addCommandsPaths',
            'custom_input'   => 'setCustomInput',
            'paths.download' => 'setDownloadPath',
            'paths.upload'   => 'setUploadPath',
        ];
        // For simple telegram extras, just pass the single param value to the Telegram method.
        foreach ($simple_extras as $param_key => $method) {
            $param = $this->params->getBotParam($param_key);
            if (null !== $param) {
                $this->telegram->$method($param);
            }
        }

        // Custom command configs.
        $command_configs = $this->params->getBotParam('commands.configs', []);
        foreach ($command_configs as $command => $config) {
            $this->telegram->setCommandConfig($command, $config);
        }

        // Botan with options.
        if ($botan_token = $this->params->getBotParam('botan.token')) {
            $botan_options = $this->params->getBotParam('botan.options', []);
            $this->telegram->enableBotan($botan_token, $botan_options);
        }

        return $this;
    }

    /**
     * Set extra bot parameters for Request class.
     *
     * @return \NPM\TelegramBotManager\BotManager
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    protected function setBotExtrasRequest(): self
    {
        $request_extras = [
            // None at the moment...
        ];
        // For request extras, just pass the single param value to the Request method.
        foreach ($request_extras as $param_key => $method) {
            $param = $this->params->getBotParam($param_key);
            if (null !== $param) {
                Request::$method($param);
            }
        }

        // Special cases.
        $limiter_enabled = $this->params->getBotParam('limiter.enabled');
        if ($limiter_enabled !== null) {
            $limiter_options = $this->params->getBotParam('limiter.options', []);
            Request::setLimiter($limiter_enabled, $limiter_options);
        }

        return $this;
    }

    /**
     * Handle the request, which calls either the Webhook or getUpdates method respectively.
     *
     * @return \NPM\TelegramBotManager\BotManager
     * @throws \NPM\TelegramBotManager\Exception\InvalidAccessException
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    public function handleRequest(): self
    {
        if (empty($this->params->getBotParam('webhook.url'))) {
            if ($loop_time = $this->getLoopTime()) {
                $this->handleGetUpdatesLoop($loop_time, $this->getLoopInterval());
            } else {
                $this->handleGetUpdates();
            }
        } else {
            $this->handleWebhook();
        }

        return $this;
    }

    /**
     * Get the number of seconds the script should loop.
     *
     * @return int
     */
    public function getLoopTime(): int
    {
        $loop_time = $this->params->getScriptParam('l');

        if (null === $loop_time) {
            return 0;
        }

        if (is_string($loop_time) && '' === trim($loop_time)) {
            return 604800; // Default to 7 days.
        }

        return max(0, (int) $loop_time);
    }

    /**
     * Get the number of seconds the script should wait after each getUpdates request.
     *
     * @return int
     */
    public function getLoopInterval(): int
    {
        $interval_time = $this->params->getScriptParam('i');

        if (null === $interval_time || (is_string($interval_time) && '' === trim($interval_time))) {
            return 2;
        }

        // Minimum interval is 1 second.
        return max(1, (int) $interval_time);
    }

    /**
     * Loop the getUpdates method for the passed amount of seconds.
     *
     * @param int $loop_time_in_seconds
     * @param int $loop_interval_in_seconds
     *
     * @return \NPM\TelegramBotManager\BotManager
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    public function handleGetUpdatesLoop(int $loop_time_in_seconds, int $loop_interval_in_seconds = 2): self
    {
        // Remember the time we started this loop.
        $now = time();

        $this->handleOutput('Looping getUpdates until ' . date('Y-m-d H:i:s', $now + $loop_time_in_seconds) . PHP_EOL);

        while ($now > time() - $loop_time_in_seconds) {
            $this->handleGetUpdates();

            // Chill a bit.
            sleep($loop_interval_in_seconds);
        }

        return $this;
    }

    /**
     * Handle the updates using the getUpdates method.
     *
     * @return \NPM\TelegramBotManager\BotManager
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    public function handleGetUpdates(): self
    {
        $output = date('Y-m-d H:i:s', time()) . ' - ';

        $response = $this->telegram->handleGetUpdates();
        if ($response->isOk()) {
            $results = array_filter((array) $response->getResult());

            $output .= sprintf('Updates processed: %d' . PHP_EOL, count($results));

            /** @var Entities\Update $result */
            foreach ($results as $result) {
                $chat_id = 0;
                $text    = 'Nothing';

                $update_content = $result->getUpdateContent();
                if ($update_content instanceof Entities\Message) {
                    $chat_id = $update_content->getFrom()->getId();
                    $text    = $update_content->getText();
                } elseif ($update_content instanceof Entities\InlineQuery ||
                          $update_content instanceof Entities\ChosenInlineResult
                ) {
                    $chat_id = $update_content->getFrom()->getId();
                    $text    = $update_content->getQuery();
                }

                $output .= sprintf(
                    '%d: %s' . PHP_EOL,
                    $chat_id,
                    preg_replace('/\s+/', ' ', trim($text))
                );
            }
        } else {
            $output .= sprintf('Failed to fetch updates: %s' . PHP_EOL, $response->printError());
        }

        $this->handleOutput($output);

        return $this;
    }

    /**
     * Handle the updates using the Webhook method.
     *
     * @return \NPM\TelegramBotManager\BotManager
     * @throws \Longman\TelegramBot\Exception\TelegramException
     * @throws \NPM\TelegramBotManager\Exception\InvalidAccessException
     */
    public function handleWebhook(): self
    {
        if (!$this->isValidRequest()) {
            throw new InvalidAccessException('Invalid access');
        }

        $this->telegram->handle();

        return $this;
    }

    /**
     * Return the current test output and clear it.
     *
     * @return string
     */
    public function getOutput(): string
    {
        $output       = $this->output;
        $this->output = '';

        return $output;
    }

    /**
     * Check if this is a valid request coming from a Telegram API IP address.
     *
     * @link https://core.telegram.org/bots/webhooks#the-short-version
     *
     * @return bool
     */
    public function isValidRequest(): bool
    {
        if ((!self::inTest() && 'cli' === PHP_SAPI) || false === $this->params->getBotParam('validate_request')) {
            return true;
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        foreach (['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR'] as $key) {
            if (filter_var($_SERVER[$key] ?? null, FILTER_VALIDATE_IP)) {
                $ip = $_SERVER[$key];
                break;
            }
        }

        $valid_ips = array_merge(
            [self::TELEGRAM_IP_RANGE],
            (array) $this->params->getBotParam('valid_ips', [])
        );
        foreach ($valid_ips as $valid_ip) {
            if (IpTools::ipInRange($ip, $valid_ip)) {
                return true;
            }
        }

        return false;
    }
}
