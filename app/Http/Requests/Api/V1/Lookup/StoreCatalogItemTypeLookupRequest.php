<?php

namespace App\Http\Requests\Api\V1\Lookup;

class StoreCatalogItemTypeLookupRequest extends StoreLookupRequest
{
    protected function table(): string
    {
        return 'catalog_item_types';
    }
}
