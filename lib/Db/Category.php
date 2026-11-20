<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getCode()
 * @method void setCode(string $v)
 * @method string getName()
 * @method void setName(string $v)
 * @method int getReceiptRequired()
 * @method void setReceiptRequired(int $v)
 * @method ?int getJustificationThresholdMinor()
 * @method void setJustificationThresholdMinor(?int $v)
 * @method int getJustificationMinLength()
 * @method void setJustificationMinLength(int $v)
 * @method ?int getDefaultAmountMinor()
 * @method void setDefaultAmountMinor(?int $v)
 * @method int getRequiresAttendees()
 * @method void setRequiresAttendees(int $v)
 * @method ?int getMaxAmountMinor()
 * @method void setMaxAmountMinor(?int $v)
 * @method int getMaxIsHard()
 * @method void setMaxIsHard(int $v)
 * @method string getAllowLines()
 * @method void setAllowLines(string $v)
 * @method int getActive()
 * @method void setActive(int $v)
 * @method int getSort()
 * @method void setSort(int $v)
 * @method int getDeleted()
 * @method void setDeleted(int $v)
 */
class Category extends Entity
{
	protected string $code = '';
	protected string $name = '';
	protected int $receiptRequired = 0;
	protected ?int $justificationThresholdMinor = null;
	protected int $justificationMinLength = 0;
	protected ?int $defaultAmountMinor = null;
	protected int $requiresAttendees = 0;
	protected ?int $maxAmountMinor = null;
	protected int $maxIsHard = 0;
	protected string $allowLines = '';
	protected int $active = 0;
	protected int $sort = 0;
	protected int $deleted = 0;

	public function __construct()
	{
		$this->addType('code', 'string');
		$this->addType('name', 'string');
		$this->addType('receiptRequired', 'integer');
		$this->addType('justificationThresholdMinor', 'integer');
		$this->addType('justificationMinLength', 'integer');
		$this->addType('defaultAmountMinor', 'integer');
		$this->addType('requiresAttendees', 'integer');
		$this->addType('maxAmountMinor', 'integer');
		$this->addType('maxIsHard', 'integer');
		$this->addType('allowLines', 'string');
		$this->addType('active', 'integer');
		$this->addType('sort', 'integer');
		$this->addType('deleted', 'integer');
	}
}
