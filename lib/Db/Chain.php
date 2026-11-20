<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getName()
 * @method void setName(string $v)
 * @method int getActive()
 * @method void setActive(int $v)
 * @method ?int getMinTotalMinor()
 * @method void setMinTotalMinor(?int $v)
 * @method ?int getCategoryId()
 * @method void setCategoryId(?int $v)
 * @method int getSort()
 * @method void setSort(int $v)
 */
class Chain extends Entity
{
	protected string $name = '';
	protected int $active = 0;
	protected ?int $minTotalMinor = null;
	protected ?int $categoryId = null;
	protected int $sort = 0;

	public function __construct()
	{
		$this->addType('name', 'string');
		$this->addType('active', 'integer');
		$this->addType('minTotalMinor', 'integer');
		$this->addType('categoryId', 'integer');
		$this->addType('sort', 'integer');
	}
}
