<?php
/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2021. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace Webvision\NinjaZugferd\Http\Controllers;

use App\Events\Credit\CreditWasEmailed;
use App\Events\Quote\QuoteWasEmailed;
use App\Http\Middleware\UserVerified;
use App\Http\Requests\Email\SendEmailRequest;
use App\Jobs\Mail\EntitySentMailer;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\RecurringInvoice;
use App\Transformers\CreditTransformer;
use App\Transformers\InvoiceTransformer;
use App\Transformers\QuoteTransformer;
use App\Transformers\RecurringInvoiceTransformer;
use App\Utils\Ninja;
use App\Utils\Traits\MakesHash;
use Illuminate\Http\Response;
use App\Http\Controllers\BaseController;
use Webvision\NinjaZugferd\Jobs\Invoice\CreateZugferd;

class TestController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Returns a template filled with entity variables.
     *
     * @param SendEmailRequest $request
     * @return Response
     *
     */
    public function send(SendEmailRequest $request)
    {
        $entity = $request->input('entity');
        $entity_obj = $entity::withTrashed()->with('invitations')->find($request->input('entity_id'));

        $entity_obj->invitations->each(function ($invitation) use ($entity_obj) {

            if (!$invitation->contact->trashed() && $invitation->contact->email) {

                $entity_obj->service()->markSent()->save();

                $xml = CreateZugferd::dispatch($invitation->fresh()->invoice);
                var_dump($xml);
            }

        });

        die();
    }
}
