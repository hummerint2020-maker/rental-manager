<?php

declare(strict_types=1);

namespace RentalManager\Tests\Support;

use RentalManager\Kernel\Db\DbException;
use RentalManager\Kernel\Db\TransactionManager;
use Throwable;

final class RecordingTransactionManager implements TransactionManager
{
    /** @var list<string> */
    public array $steps = [];

    public function transaction(callable $work): mixed
    {
        for ($attempt = 1;; ++$attempt) {
            $this->steps[] = 'begin';
            try {
                $result = $work();
            } catch (Throwable $failure) {
                $this->steps[] = 'rollback';
                if ($attempt === 1 && $failure instanceof DbException && $failure->getCode() === 1213) {
                    continue;
                }
                throw $failure;
            }
            $this->steps[] = 'commit';
            return $result;
        }
    }
}
