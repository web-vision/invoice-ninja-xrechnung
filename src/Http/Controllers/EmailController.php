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
use Webvision\NinjaZugferd\Jobs\Entity\EmailEntity;
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

class EmailController extends BaseController
{
    use MakesHash;

    protected $entity_type = Invoice::class;

    protected $entity_transformer = InvoiceTransformer::class;

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
        $subject = $request->has('subject') ? $request->input('subject') : '';
        $body = $request->has('body') ? $request->input('body') : '';
        $entity_string = strtolower(class_basename($entity_obj));
        $template = str_replace("email_template_", "", $request->input('template'));

        $data = [
            'subject' => $subject,
            'body' => $body
        ];

        $entity_obj->invitations->each(function ($invitation) use ($data, $entity_string, $entity_obj, $template) {

            if (!$invitation->contact->trashed() && $invitation->contact->email) {

                $entity_obj->service()->markSent()->save();

                EmailEntity::dispatch($invitation->fresh(), $invitation->company, $template, $data);
            }

        });

        $entity_obj->last_sent_date = now();

        $entity_obj->save();

        /*Only notify the admin ONCE, not once per contact/invite*/
        if ($entity_obj instanceof Invoice) {
            $this->entity_type = Invoice::class;
            $this->entity_transformer = InvoiceTransformer::class;

            if ($entity_obj->invitations->count() >= 1)
                $entity_obj->entityEmailEvent($entity_obj->invitations->first(), 'invoice', $template);

        }

        if ($entity_obj instanceof Quote) {
            $this->entity_type = Quote::class;
            $this->entity_transformer = QuoteTransformer::class;

            if ($entity_obj->invitations->count() >= 1)
                event(new QuoteWasEmailed($entity_obj->invitations->first(), $entity_obj->company, Ninja::eventVars(auth()->user() ? auth()->user()->id : null), 'quote'));

        }

        if ($entity_obj instanceof Credit) {
            $this->entity_type = Credit::class;
            $this->entity_transformer = CreditTransformer::class;

            if ($entity_obj->invitations->count() >= 1)
                event(new CreditWasEmailed($entity_obj->invitations->first(), $entity_obj->company, Ninja::eventVars(auth()->user() ? auth()->user()->id : null), 'credit'));

        }

        if ($entity_obj instanceof RecurringInvoice) {
            $this->entity_type = RecurringInvoice::class;
            $this->entity_transformer = RecurringInvoiceTransformer::class;
        }

        return $this->itemResponse($entity_obj->fresh());
    }
}
