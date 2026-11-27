<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http\Policy;

/**
 * Cohesive immutable safety policy composite grouping outbound HTTP redirect
 * rules, SSRF network/host filters, and wire-transfer bounds.
 */
final readonly class OutboundSafetyPolicy
{
    public function __construct(
        public RedirectPolicy $redirects = new RedirectPolicy(),
        public NetworkPolicy $network = new NetworkPolicy(),
        public TransferPolicy $transfer = new TransferPolicy(),
    ) {}

    public static function default(): self
    {
        return new self();
    }

    public function withRedirects(RedirectPolicy $redirects): self
    {
        return new self(
            redirects: $redirects,
            network: $this->network,
            transfer: $this->transfer,
        );
    }

    public function withNetwork(NetworkPolicy $network): self
    {
        return new self(
            redirects: $this->redirects,
            network: $network,
            transfer: $this->transfer,
        );
    }

    public function withTransfer(TransferPolicy $transfer): self
    {
        return new self(
            redirects: $this->redirects,
            network: $this->network,
            transfer: $transfer,
        );
    }
}
