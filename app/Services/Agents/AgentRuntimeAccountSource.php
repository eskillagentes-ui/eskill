<?php

declare(strict_types=1);

namespace App\Services\Agents;

use PDO;
use UnexpectedValueException;

final class AgentRuntimeAccountSource implements AgentRuntimeAccountSourceInterface
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** @return list<int> */
    public function activeAccountIds(): array
    {
        $statement = $this->db->prepare(
            'SELECT id FROM ml_accounts WHERE status = :status ORDER BY id ASC LIMIT 201'
        );
        $statement->execute(['status' => 'active']);
        $rows = $statement->fetchAll(PDO::FETCH_COLUMN);
        if (!is_array($rows)) {
            throw new UnexpectedValueException('invalid account source');
        }
        if (count($rows) > 200) {
            throw new UnexpectedValueException('account source limit exceeded');
        }

        $ids = [];
        foreach ($rows as $row) {
            if (!is_int($row) && !(is_string($row) && preg_match('/^[1-9][0-9]*$/D', $row) === 1)) {
                throw new UnexpectedValueException('invalid account source');
            }
            $id = (int) $row;
            if ($id <= 0 || isset($ids[$id])) {
                throw new UnexpectedValueException('invalid account source');
            }
            $ids[$id] = $id;
        }

        return array_values($ids);
    }
}
