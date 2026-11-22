<?php
declare(strict_types = 1);

namespace youvision;

/**
 * 鉴权通过后的身份。uid 用于 Gateway::bindUid，extra 会写入连接 session
 */
final readonly class Identity
{
    public function __construct(
        public string $uid,
        public array $extra = [],
        public ?int $expiresAt = null,
    ) {
        if ($uid === '') {
            throw new \InvalidArgumentException('Identity uid 不能为空');
        }
    }

    /**
     * 令牌是否已过期；expiresAt 为 null 表示不过期
     */
    public function expired(?int $now = null): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= ($now ?? time());
    }
}
