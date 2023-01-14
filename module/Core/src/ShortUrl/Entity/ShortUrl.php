<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\ShortUrl\Entity;

use Cake\Chronos\Chronos;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\Common\Collections\Selectable;
use Shlinkio\Shlink\Common\Entity\AbstractEntity;
use Shlinkio\Shlink\Core\Domain\Entity\Domain;
use Shlinkio\Shlink\Core\Exception\ShortCodeCannotBeRegeneratedException;
use Shlinkio\Shlink\Core\ShortUrl\Model\ShortUrlCreation;
use Shlinkio\Shlink\Core\ShortUrl\Model\ShortUrlEdition;
use Shlinkio\Shlink\Core\ShortUrl\Model\Validation\ShortUrlInputFilter;
use Shlinkio\Shlink\Core\ShortUrl\Resolver\ShortUrlRelationResolverInterface;
use Shlinkio\Shlink\Core\ShortUrl\Resolver\SimpleShortUrlRelationResolver;
use Shlinkio\Shlink\Core\Tag\Entity\Tag;
use Shlinkio\Shlink\Core\Visit\Entity\Visit;
use Shlinkio\Shlink\Core\Visit\Model\VisitType;
use Shlinkio\Shlink\Importer\Model\ImportedShlinkUrl;
use Shlinkio\Shlink\Rest\Entity\ApiKey;

use function count;
use function Shlinkio\Shlink\Core\generateRandomShortCode;
use function Shlinkio\Shlink\Core\normalizeDate;
use function Shlinkio\Shlink\Core\normalizeOptionalDate;

class ShortUrl extends AbstractEntity
{
    private string $longUrl;
    private string $shortCode;
    private Chronos $dateCreated;
    /** @var Collection<int, Visit> */
    private Collection $visits;
    /** @var Collection<int, Tag> */
    private Collection $tags;
    private ?Chronos $validSince = null;
    private ?Chronos $validUntil = null;
    private ?int $maxVisits = null;
    private ?Domain $domain = null;
    private bool $customSlugWasProvided;
    private int $shortCodeLength;
    private ?string $importSource = null;
    private ?string $importOriginalShortCode = null;
    private ?ApiKey $authorApiKey = null;
    private ?string $title = null;
    private bool $titleWasAutoResolved = false;
    private bool $crawlable = false;
    private bool $forwardQuery = true;

    private function __construct()
    {
    }

    /**
     * @deprecated This should not be allowed
     */
    public static function createEmpty(): self
    {
        return self::create(ShortUrlCreation::createEmpty());
    }

    /**
     * @param non-empty-string $longUrl
     */
    public static function withLongUrl(string $longUrl): self
    {
        return self::create(ShortUrlCreation::fromRawData([ShortUrlInputFilter::LONG_URL => $longUrl]));
    }

    public static function create(
        ShortUrlCreation $creation,
        ?ShortUrlRelationResolverInterface $relationResolver = null,
    ): self {
        $instance = new self();
        $relationResolver = $relationResolver ?? new SimpleShortUrlRelationResolver();

        $instance->longUrl = $creation->getLongUrl();
        $instance->dateCreated = Chronos::now();
        $instance->visits = new ArrayCollection();
        $instance->tags = $relationResolver->resolveTags($creation->tags);
        $instance->validSince = $creation->validSince;
        $instance->validUntil = $creation->validUntil;
        $instance->maxVisits = $creation->maxVisits;
        $instance->customSlugWasProvided = $creation->hasCustomSlug();
        $instance->shortCodeLength = $creation->shortCodeLength;
        $instance->shortCode = $creation->customSlug ?? generateRandomShortCode($instance->shortCodeLength);
        $instance->domain = $relationResolver->resolveDomain($creation->domain);
        $instance->authorApiKey = $creation->apiKey;
        $instance->title = $creation->title;
        $instance->titleWasAutoResolved = $creation->titleWasAutoResolved;
        $instance->crawlable = $creation->crawlable;
        $instance->forwardQuery = $creation->forwardQuery;

        return $instance;
    }

    public static function fromImport(
        ImportedShlinkUrl $url,
        bool $importShortCode,
        ?ShortUrlRelationResolverInterface $relationResolver = null,
    ): self {
        $meta = [
            ShortUrlInputFilter::VALIDATE_URL => false,
            ShortUrlInputFilter::LONG_URL => $url->longUrl,
            ShortUrlInputFilter::DOMAIN => $url->domain,
            ShortUrlInputFilter::TAGS => $url->tags,
            ShortUrlInputFilter::TITLE => $url->title,
            ShortUrlInputFilter::MAX_VISITS => $url->meta->maxVisits,
        ];
        if ($importShortCode) {
            $meta[ShortUrlInputFilter::CUSTOM_SLUG] = $url->shortCode;
        }

        $instance = self::create(ShortUrlCreation::fromRawData($meta), $relationResolver);

        $instance->importSource = $url->source->value;
        $instance->importOriginalShortCode = $url->shortCode;
        $instance->validSince = normalizeOptionalDate($url->meta->validSince);
        $instance->validUntil = normalizeOptionalDate($url->meta->validUntil);
        $instance->dateCreated = normalizeDate($url->createdAt);

        return $instance;
    }

    public function getLongUrl(): string
    {
        return $this->longUrl;
    }

    public function getShortCode(): string
    {
        return $this->shortCode;
    }

    public function getDateCreated(): Chronos
    {
        return $this->dateCreated;
    }

    public function getDomain(): ?Domain
    {
        return $this->domain;
    }

    /**
     * @return Collection<int, Tag>
     */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function authorApiKey(): ?ApiKey
    {
        return $this->authorApiKey;
    }

    public function getValidSince(): ?Chronos
    {
        return $this->validSince;
    }

    public function getValidUntil(): ?Chronos
    {
        return $this->validUntil;
    }

    public function getVisitsCount(): int
    {
        return count($this->visits);
    }

    public function nonBotVisitsCount(): int
    {
        $criteria = Criteria::create()->where(Criteria::expr()->eq('potentialBot', false));
        return count($this->visits->matching($criteria));
    }

    public function mostRecentImportedVisitDate(): ?Chronos
    {
        /** @var Selectable $visits */
        $visits = $this->visits;
        $criteria = Criteria::create()->where(Criteria::expr()->eq('type', VisitType::IMPORTED))
                                      ->orderBy(['id' => 'DESC'])
                                      ->setMaxResults(1);

        /** @var Visit|false $visit */
        $visit = $visits->matching($criteria)->last();

        return $visit === false ? null : $visit->getDate();
    }

    /**
     * @param Collection<int, Visit> $visits
     * @internal
     */
    public function setVisits(Collection $visits): self
    {
        $this->visits = $visits;
        return $this;
    }

    public function getMaxVisits(): ?int
    {
        return $this->maxVisits;
    }

    public function title(): ?string
    {
        return $this->title;
    }

    public function crawlable(): bool
    {
        return $this->crawlable;
    }

    public function forwardQuery(): bool
    {
        return $this->forwardQuery;
    }

    public function update(
        ShortUrlEdition $shortUrlEdit,
        ?ShortUrlRelationResolverInterface $relationResolver = null,
    ): void {
        if ($shortUrlEdit->validSinceWasProvided()) {
            $this->validSince = $shortUrlEdit->validSince;
        }
        if ($shortUrlEdit->validUntilWasProvided()) {
            $this->validUntil = $shortUrlEdit->validUntil;
        }
        if ($shortUrlEdit->maxVisitsWasProvided()) {
            $this->maxVisits = $shortUrlEdit->maxVisits;
        }
        if ($shortUrlEdit->longUrlWasProvided()) {
            $this->longUrl = $shortUrlEdit->longUrl ?? $this->longUrl;
        }
        if ($shortUrlEdit->tagsWereProvided()) {
            $relationResolver = $relationResolver ?? new SimpleShortUrlRelationResolver();
            $this->tags = $relationResolver->resolveTags($shortUrlEdit->tags);
        }
        if ($shortUrlEdit->crawlableWasProvided()) {
            $this->crawlable = $shortUrlEdit->crawlable;
        }
        if (
            $this->title === null
            || $shortUrlEdit->titleWasProvided()
            || ($this->titleWasAutoResolved && $shortUrlEdit->titleWasAutoResolved())
        ) {
            $this->title = $shortUrlEdit->title;
            $this->titleWasAutoResolved = $shortUrlEdit->titleWasAutoResolved();
        }
        if ($shortUrlEdit->forwardQueryWasProvided()) {
            $this->forwardQuery = $shortUrlEdit->forwardQuery;
        }
    }

    /**
     * @throws ShortCodeCannotBeRegeneratedException
     */
    public function regenerateShortCode(): void
    {
        // In ShortUrls where a custom slug was provided, throw error, unless it is an imported one
        if ($this->customSlugWasProvided && $this->importSource === null) {
            throw ShortCodeCannotBeRegeneratedException::forShortUrlWithCustomSlug();
        }

        // The short code can be regenerated only on ShortUrl which have not been persisted yet
        if ($this->id !== null) {
            throw ShortCodeCannotBeRegeneratedException::forShortUrlAlreadyPersisted();
        }

        $this->shortCode = generateRandomShortCode($this->shortCodeLength);
    }

    public function isEnabled(): bool
    {
        $maxVisitsReached = $this->maxVisits !== null && $this->getVisitsCount() >= $this->maxVisits;
        if ($maxVisitsReached) {
            return false;
        }

        $now = Chronos::now();
        $beforeValidSince = $this->validSince !== null && $this->validSince->gt($now);
        if ($beforeValidSince) {
            return false;
        }

        $afterValidUntil = $this->validUntil !== null && $this->validUntil->lt($now);
        if ($afterValidUntil) {
            return false;
        }

        return true;
    }
}
