<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method ?int getClaimId()
 * @method void setClaimId(?int $v)
 * @method ?int getLineId()
 * @method void setLineId(?int $v)
 * @method int getInbox()
 * @method void setInbox(int $v)
 * @method int getFileId()
 * @method void setFileId(int $v)
 * @method string getName()
 * @method void setName(string $v)
 * @method string getMime()
 * @method void setMime(string $v)
 * @method int getSize()
 * @method void setSize(int $v)
 * @method string getSha256()
 * @method void setSha256(string $v)
 * @method string getUploadedBy()
 * @method void setUploadedBy(string $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 */
class Attachment extends Entity
{
	protected ?int $claimId = null;
	protected ?int $lineId = null;
	protected int $inbox = 0;
	protected int $fileId = 0;
	protected string $name = '';
	protected string $mime = '';
	protected int $size = 0;
	protected string $sha256 = '';
	protected string $uploadedBy = '';
	protected ?\DateTime $createdAt = null;

	public function __construct()
	{
		$this->addType('claimId', 'integer');
		$this->addType('lineId', 'integer');
		$this->addType('inbox', 'integer');
		$this->addType('fileId', 'integer');
		$this->addType('name', 'string');
		$this->addType('mime', 'string');
		$this->addType('size', 'integer');
		$this->addType('sha256', 'string');
		$this->addType('uploadedBy', 'string');
		$this->addType('createdAt', 'datetime');
	}
}
