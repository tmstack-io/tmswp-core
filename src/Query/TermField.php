<?php

declare(strict_types=1);

namespace TMSWP\Core\Query;

/** tax_query の句の field(terms の値をタームのどの列と照合するか) */
enum TermField: string
{
    case Slug = 'slug';
    case TermId = 'term_id';
    case Name = 'name';
    case TermTaxonomyId = 'term_taxonomy_id';
}
