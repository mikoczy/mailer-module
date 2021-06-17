<?php
declare(strict_types=1);

namespace Remp\MailerModule\Presenters;

use Nette\Application\UI\Presenter;
use Nette\Http\Response;
use Nette\Utils\FileSystem;
use Remp\MailerModule\Models\Job\MailCache;
use Remp\MailerModule\Repositories\ConfigsRepository;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tracy\Debugger;
use Tracy\ILogger;

/**
 * Healtg check presenter
 *
 * Behavior and response should be same or similar to health checks within REMP (package ukfast/laravel-health-check).
 */
final class HealthPresenter extends Presenter
{
    private const STATUS_OK = 'ok';
    private const STATUS_PROBLEM = 'PROBLEM';

    private $configsRepository;

    private $mailCache;

    private $tempDir;

    public function __construct(
        string $tempDir,
        ConfigsRepository $configsRepository,
        MailCache $mailCache
    ) {
        parent::__construct();
        $this->configsRepository = $configsRepository;
        $this->mailCache = $mailCache;
        $this->tempDir = $tempDir;
    }

    public function renderDefault(): void
    {
        $result = [
            'status' => self::STATUS_OK,
        ];

        $result['database'] = $this->databaseCheck();
        if ($result['database']['status'] === self::STATUS_PROBLEM) {
            $result['status'] = self::STATUS_PROBLEM;
        }

        $result['redis'] = $this->redisCheck();
        if ($result['redis']['status'] === self::STATUS_PROBLEM) {
            $result['status'] = self::STATUS_PROBLEM;
        }

        $result['log'] = $this->logCheck();
        if ($result['log']['status'] === self::STATUS_PROBLEM) {
            $result['status'] = self::STATUS_PROBLEM;
        }

        $result['storage'] = $this->storageCheck();
        if ($result['storage']['status'] === self::STATUS_PROBLEM) {
            $result['status'] = self::STATUS_PROBLEM;
        }

        // set correct response code and return results
        if ($result['status'] === self::STATUS_OK) {
            $resultCode = Response::S200_OK;
        } else {
            $resultCode = Response::S500_INTERNAL_SERVER_ERROR;
        }

        $this->sendResponse(new JsonApiResponse($resultCode, $result));
    }

    private function databaseCheck(): array
    {
        $result['status'] = self::STATUS_OK;

        try {
            // fetching default_mailer which is always present in seeded DB
            $defaultMailerConfigString = 'default_mailer';
            $defaultMailer = $this->configsRepository->loadByName($defaultMailerConfigString);
            if (!$defaultMailer) {
                throw new \Exception("Unable to find [{$defaultMailerConfigString}] config. Did you migrate & seed database?");
            }
        } catch (\Exception $e) {
            $result = [
                'status' => self::STATUS_PROBLEM,
                // https://github.com/ukfast/laravel-health-check/blob/9979cd58831f42fdd284e882eaed4d74362e1641/src/Checks/DatabaseHealthCheck.php#L31
                'message' => 'Could not connect to db',
                'context' => $e->getMessage()
            ];
        }

        return $result;
    }

    private function redisCheck(): array
    {
        $result['status'] = self::STATUS_OK;

        try {
            $pingPongResponse = 'PONG';
            $redisPing = $this->mailCache->ping($pingPongResponse);
            if ($redisPing !== $pingPongResponse) {
                throw new \Exception('Unable to ping redis.');
            }
        } catch (\Exception $e) {
            $result = [
                'status' => self::STATUS_PROBLEM,
                // https://github.com/ukfast/laravel-health-check/blob/9979cd58831f42fdd284e882eaed4d74362e1641/src/Checks/RedisHealthCheck.php#L18
                'message' => 'Failed to connect to redis',
                'context' => $e->getMessage(),
            ];
        }

        return $result;
    }

    private function logCheck(): array
    {
        $result['status'] = self::STATUS_OK;

        try {
            // see \Tracy\Logger::log() for exceptions
            Debugger::log('Healthcheck ping', ILogger::DEBUG);
        } catch (\Exception $e) {
            $result = [
                'status' => self::STATUS_PROBLEM,
                // https://github.com/ukfast/laravel-health-check/blob/9979cd58831f42fdd284e882eaed4d74362e1641/src/Checks/LogHealthCheck.php#L25
                'message' => 'Could not write to log file',
                'context' => $e->getMessage(),
            ];
        }

        return $result;
    }

    private function storageCheck(): array
    {
        $result['status'] = self::STATUS_OK;

        try {
            $filePath = $this->tempDir . DIRECTORY_SEPARATOR . 'healtcheck';
            $fileContent = 'healthcheck';
            FileSystem::write($filePath, $fileContent);
            $fileContentRead = FileSystem::read($filePath);
            FileSystem::delete($filePath);

            if ($fileContentRead !== $fileContent) {
                throw new \Exception('Contents of written file are not same.');
            }
        } catch (\Exception $e) {
            $result = [
                'status' => self::STATUS_PROBLEM,
                'message' => 'Could not write to temp dir',
                'context' => $e->getMessage(),
            ];
        }

        return $result;
    }
}
