<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getCode()
 * @method void setCode(string $v)
 * @method string getName()
 * @method void setName(string $v)
 * @method ?int getParentId()
 * @method void setParentId(?int $v)
 * @method ?int getAzcTeamId()
 * @method void setAzcTeamId(?int $v)
 * @method ?int getBudgetMinor()
 * @method void setBudgetMinor(?int $v)
 * @method ?string getCurrency()
 * @method void setCurrency(?string $v)
 * @method int getActive()
 * @method void setActive(int $v)
 * @method int getDeleted()
 * @method void setDeleted(int $v)
 */
class CostCenter extends Entity
{
	protected string $code = '';
	protected string $name = '';
	protected ?int $parentId = null;
	protected ?int $azcTeamId = null;
	protected ?int $budgetMinor = null;
	protected ?string $currency = null;
	protected int $active = 0;
	protected int $deleted = 0;

	public function __construct()
	{
		$this->addType('code', 'string');
		$this->addType('name', 'string');
		$this->addType('parentId', 'integer');
		$this->addType('azcTeamId', 'integer');
		$this->addType('budgetMinor', 'integer');
		$this->addType('currency', 'string');
		$this->addType('active', 'integer');
		$this->addType('deleted', 'integer');
	}
}
