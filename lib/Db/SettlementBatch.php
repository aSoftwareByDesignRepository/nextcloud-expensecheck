<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 * @method string getFormat()
 * @method void setFormat(string $v)
 * @method int getRowCount()
 * @method void setRowCount(int $v)
 * @method ?string getFileHash()
 * @method void setFileHash(?string $v)
 * @method ?int getPeriodId()
 * @method void setPeriodId(?int $v)
 */
class SettlementBatch extends Entity
{
	protected string $createdBy = '';
	protected ?\DateTime $createdAt = null;
	protected string $format = '';
	protected int $rowCount = 0;
	protected ?string $fileHash = null;
	protected ?int $periodId = null;

	public function __construct()
	{
		$this->addType('createdBy', 'string');
		$this->addType('createdAt', 'datetime');
		$this->addType('format', 'string');
		$this->addType('rowCount', 'integer');
		$this->addType('fileHash', 'string');
		$this->addType('periodId', 'integer');
	}
}
