<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getClaimId()
 * @method void setClaimId(int $v)
 * @method string getLineType()
 * @method void setLineType(string $v)
 * @method \DateTime getOccurredOn()
 * @method void setOccurredOn(\DateTime $v)
 * @method int getCategoryId()
 * @method void setCategoryId(int $v)
 * @method ?string getMerchant()
 * @method void setMerchant(?string $v)
 * @method ?string getDescription()
 * @method void setDescription(?string $v)
 * @method int getAmountMinor()
 * @method void setAmountMinor(int $v)
 * @method ?int getTaxMinor()
 * @method void setTaxMinor(?int $v)
 * @method ?string getTaxRate()
 * @method void setTaxRate(?string $v)
 * @method ?int getForeignAmountMinor()
 * @method void setForeignAmountMinor(?int $v)
 * @method ?string getForeignCurrency()
 * @method void setForeignCurrency(?string $v)
 * @method ?string getForeignFx()
 * @method void setForeignFx(?string $v)
 * @method ?string getDistanceKm()
 * @method void setDistanceKm(?string $v)
 * @method ?string getDays()
 * @method void setDays(?string $v)
 * @method ?string getQuantity()
 * @method void setQuantity(?string $v)
 * @method ?int getRateId()
 * @method void setRateId(?int $v)
 * @method ?string getRateCode()
 * @method void setRateCode(?string $v)
 * @method ?int getRateMinor()
 * @method void setRateMinor(?int $v)
 * @method ?string getRateName()
 * @method void setRateName(?string $v)
 * @method ?string getJustification()
 * @method void setJustification(?string $v)
 * @method int getReimbursable()
 * @method void setReimbursable(int $v)
 * @method ?string getDistanceSource()
 * @method void setDistanceSource(?string $v)
 * @method ?int getRouteFileId()
 * @method void setRouteFileId(?int $v)
 * @method ?string getSourceApp()
 * @method void setSourceApp(?string $v)
 * @method ?string getSourceRef()
 * @method void setSourceRef(?string $v)
 * @method ?string getAttendees()
 * @method void setAttendees(?string $v)
 * @method ?string getOccasion()
 * @method void setOccasion(?string $v)
 * @method ?int getTipMinor()
 * @method void setTipMinor(?int $v)
 * @method ?string getPolicyFlags()
 * @method void setPolicyFlags(?string $v)
 * @method int getSort()
 * @method void setSort(int $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $v)
 */
class ClaimLine extends Entity
{
	protected int $claimId = 0;
	protected string $lineType = '';
	protected ?\DateTime $occurredOn = null;
	protected int $categoryId = 0;
	protected ?string $merchant = null;
	protected ?string $description = null;
	protected int $amountMinor = 0;
	protected ?int $taxMinor = null;
	protected ?string $taxRate = null;
	protected ?int $foreignAmountMinor = null;
	protected ?string $foreignCurrency = null;
	protected ?string $foreignFx = null;
	protected ?string $distanceKm = null;
	protected ?string $days = null;
	protected ?string $quantity = null;
	protected ?int $rateId = null;
	protected ?string $rateCode = null;
	protected ?int $rateMinor = null;
	protected ?string $rateName = null;
	protected ?string $justification = null;
	protected int $reimbursable = 0;
	protected ?string $distanceSource = null;
	protected ?int $routeFileId = null;
	protected ?string $sourceApp = null;
	protected ?string $sourceRef = null;
	protected ?string $attendees = null;
	protected ?string $occasion = null;
	protected ?int $tipMinor = null;
	protected ?string $policyFlags = null;
	protected int $sort = 0;
	protected ?\DateTime $createdAt = null;
	protected ?\DateTime $updatedAt = null;

	public function __construct()
	{
		$this->addType('claimId', 'integer');
		$this->addType('lineType', 'string');
		$this->addType('occurredOn', 'datetime');
		$this->addType('categoryId', 'integer');
		$this->addType('merchant', 'string');
		$this->addType('description', 'string');
		$this->addType('amountMinor', 'integer');
		$this->addType('taxMinor', 'integer');
		$this->addType('taxRate', 'string');
		$this->addType('foreignAmountMinor', 'integer');
		$this->addType('foreignCurrency', 'string');
		$this->addType('foreignFx', 'string');
		$this->addType('distanceKm', 'string');
		$this->addType('days', 'string');
		$this->addType('quantity', 'string');
		$this->addType('rateId', 'integer');
		$this->addType('rateCode', 'string');
		$this->addType('rateMinor', 'integer');
		$this->addType('rateName', 'string');
		$this->addType('justification', 'string');
		$this->addType('reimbursable', 'integer');
		$this->addType('distanceSource', 'string');
		$this->addType('routeFileId', 'integer');
		$this->addType('sourceApp', 'string');
		$this->addType('sourceRef', 'string');
		$this->addType('attendees', 'string');
		$this->addType('occasion', 'string');
		$this->addType('tipMinor', 'integer');
		$this->addType('policyFlags', 'string');
		$this->addType('sort', 'integer');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}
}
