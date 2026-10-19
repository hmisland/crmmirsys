<?php

declare(strict_types=1);

namespace App\Infrastructure\PublicLinks;

use App\Core\Request;
use App\Core\Response;

/**
 * What a module does with its own kind of public link (e.g. the
 * Customer Satisfaction survey page). PublicLinkController has already
 * validated the token (exists, active, not expired, not used up), set
 * the organization context and the link's locale, and checked the
 * handler's module entitlement before calling show()/submit().
 *
 * Handlers render with the "public_layout" layout and must never print
 * internal ids, other customers' data, or anything the link does not
 * need to show.
 */
interface PublicLinkHandlerInterface
{
    /** The public_links.purpose this handler serves, e.g. "cx_survey". */
    public function purpose(): string;

    /** Entitlement module key the link's organization must still hold; null = Core. */
    public function moduleKey(): ?string;

    public function show(PublicLink $link, Request $request): Response;

    public function submit(PublicLink $link, Request $request): Response;
}
