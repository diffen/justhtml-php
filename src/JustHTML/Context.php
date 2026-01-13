<?php

declare(strict_types=1);

namespace JustHTML;

final class FragmentContext
{
    public string $tagName;
    public ?string $namespace;

    public function __construct(string $tagName, ?string $namespace = null)
    {
        $this->tagName = $tagName;
        $this->namespace = $namespace;
    }
}
