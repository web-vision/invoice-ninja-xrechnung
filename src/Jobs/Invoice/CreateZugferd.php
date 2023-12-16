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

namespace Webvision\NinjaZugferd\Jobs\Invoice;

use App\Models\Invoice;
use Easybill\ZUGFeRD211\Model\Amount;
use Easybill\ZUGFeRD211\Model\CreditorFinancialAccount;
use Easybill\ZUGFeRD211\Model\CreditorFinancialInstitution;
use Easybill\ZUGFeRD211\Model\DateTime;
use Easybill\ZUGFeRD211\Model\DocumentContextParameter;
use Easybill\ZUGFeRD211\Model\DocumentLineDocument;
use Easybill\ZUGFeRD211\Model\ExchangedDocument;
use Easybill\ZUGFeRD211\Model\ExchangedDocumentContext;
use Easybill\ZUGFeRD211\Model\FormattedDateTime;
use Easybill\ZUGFeRD211\Model\HeaderTradeAgreement;
use Easybill\ZUGFeRD211\Model\HeaderTradeDelivery;
use Easybill\ZUGFeRD211\Model\HeaderTradeSettlement;
use Easybill\ZUGFeRD211\Model\Id;
use Easybill\ZUGFeRD211\Model\LineTradeAgreement;
use Easybill\ZUGFeRD211\Model\LineTradeDelivery;
use Easybill\ZUGFeRD211\Model\LineTradeSettlement;
use Easybill\ZUGFeRD211\Model\Note;
use Easybill\ZUGFeRD211\Model\ProcuringProject;
use Easybill\ZUGFeRD211\Model\Quantity;
use Easybill\ZUGFeRD211\Model\ReferencedDocument;
use Easybill\ZUGFeRD211\Model\SupplyChainEvent;
use Easybill\ZUGFeRD211\Model\SupplyChainTradeLineItem;
use Easybill\ZUGFeRD211\Model\SupplyChainTradeTransaction;
use Easybill\ZUGFeRD211\Model\TaxRegistration;
use Easybill\ZUGFeRD211\Model\TradeAddress;
use Easybill\ZUGFeRD211\Model\TradeContact;
use Easybill\ZUGFeRD211\Model\TradeCountry;
use Easybill\ZUGFeRD211\Model\TradeParty;
use Easybill\ZUGFeRD211\Model\TradePaymentTerms;
use Easybill\ZUGFeRD211\Model\TradePrice;
use Easybill\ZUGFeRD211\Model\TradeProduct;
use Easybill\ZUGFeRD211\Model\TradeSettlementHeaderMonetarySummation;
use Easybill\ZUGFeRD211\Model\TradeSettlementLineMonetarySummation;
use Easybill\ZUGFeRD211\Model\TradeSettlementPaymentMeans;
use Easybill\ZUGFeRD211\Model\TradeTax;
use Easybill\ZUGFeRD211\Model\UniversalCommunication;
use Easybill\ZUGFeRD211\Tests\ReaderAndBuildTest;
use Easybill\ZUGFeRD211\Validator;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Easybill\ZUGFeRD211\Builder;
use Easybill\ZUGFeRD211\Reader;
use Easybill\ZUGFeRD\Model\Document;
use Easybill\ZUGFeRD211\Model\CrossIndustryInvoice;


class CreateZugferd implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    const INVOICE_TYPE_STANDARD = 380;
    const INVOICE_TYPE_CREDIT = 381;

    public $invoice;

    public function __construct(Invoice $invoice)
    {
        $this->invoice = $invoice;
    }

    public function handle()
    {
        $invoice = $this->invoice;
        $company = $invoice->company;
        $client = $invoice->client;
        $user = $invoice->user;

        $xInvoice = new CrossIndustryInvoice();
        $xInvoice->exchangedDocumentContext = new ExchangedDocumentContext();
        $xInvoice->exchangedDocumentContext->documentContextParameter = new DocumentContextParameter();
        $xInvoice->exchangedDocumentContext->documentContextParameter->id = 'urn:cen.eu:en16931:2017#compliant#urn:xoev-de:kosit:standard:xrechnung_2.0';

        $xInvoice->exchangedDocument = new ExchangedDocument();
        $xInvoice->exchangedDocument->id = $invoice->number;
        //$xInvoice->exchangedDocument->name = 'Rechnung';

        $xInvoice->exchangedDocument->issueDateTime = DateTime::create(102, (new \DateTime($invoice->date))->format('Ymd'));
        //$invoice->exchangedDocument->issueDateTime = DateTime::create(102, '20180305');

        $xInvoice->exchangedDocument->typeCode = '380';
        //$xInvoice->exchangedDocument->languageId[] = 'de';
        if ($invoice->public_notes) {
            $xInvoice->exchangedDocument->notes[] = Note::create($invoice->public_notes);
        }

        if ($invoice->private_notes) {
            $xInvoice->exchangedDocument->notes[] = Note::create($invoice->private_notes);
        }

        $xInvoice->supplyChainTradeTransaction = new SupplyChainTradeTransaction();

        $taxable = $this->getTaxable();

        foreach ($invoice->line_items as $index => $item) {
            $xInvoice->supplyChainTradeTransaction->lineItems[] = $item1 = new SupplyChainTradeLineItem();
            $item1->associatedDocumentLineDocument = DocumentLineDocument::create('1');

            $item1->associatedDocumentLineDocument->lineStatusCode = 'TYPE_LINE';

            $item1->specifiedTradeProduct = new TradeProduct();
            $item1->specifiedTradeProduct->name = $item->product_key;
            $item1->specifiedTradeProduct->description = $item->notes;
            $item1->specifiedTradeProduct->sellerAssignedID = $company->settings->id_number;
            $item1->specifiedTradeProduct->globalID = Id::create('', '0160');



            $itemTaxable = $this->getItemTaxable($item, $taxable);


            $item1->tradeAgreement = new LineTradeAgreement(); //GrossPriceProductTradePrice
            $item1->tradeAgreement->netPrice = TradePrice::create(number_format(0, 2, '.', '')); //ChargeAmount

            $item1->tradeAgreement->grossPrice = TradePrice::create(number_format($this->costWithDiscount($item), 2, '.', ''), Quantity::create(1, 'C62')); //NetPriceProductTradePrice  //ChargeAmount //BasisQuantity

            $item1->delivery = new LineTradeDelivery();
            $item1->delivery->billedQuantity = Quantity::create($item->quantity, 'H87');

            $item1->specifiedLineTradeSettlement = new LineTradeSettlement();

            if ($invoice->tax_name1 || floatval($invoice->tax_rate1)) {
                $item1->specifiedLineTradeSettlement->tradeTax[] = $item1tax = new TradeTax();
                $item1tax->typeCode = 'VAT'; //@wvtodo
                $item1tax->categoryCode = 'S'; //@wvtodo tax name? E or S?
                $item1tax->rateApplicablePercent = $invoice->tax_rate1;

                //$taxAmount = $this->taxAmount($taxable, $item->tax_rate1);
            }

            if ($invoice->tax_name2 || floatval($invoice->tax_rate2)) {
                $item1->specifiedLineTradeSettlement->tradeTax[] = $item2tax = new TradeTax();
                $item2tax->typeCode = 'VAT'; //@wvtodo
                $item2tax->categoryCode = 'S'; //@wvtodo tax name? E or S?
                $item2tax->rateApplicablePercent = $invoice->tax_rate2;

                //$taxAmount = $this->taxAmount($taxable, $item->tax_rate1);
            }

            if ($invoice->tax_name3 || floatval($invoice->tax_rate3)) {
                $item1->specifiedLineTradeSettlement->tradeTax[] = $item3tax = new TradeTax();
                $item3tax->typeCode = 'VAT'; //@wvtodo
                $item3tax->categoryCode = 'S'; //@wvtodo tax name? E or S?
                $item3tax->rateApplicablePercent = $invoice->tax_rate3;

                //$taxAmount = $this->taxAmount($taxable, $item->tax_rate1);
            }

            if (!$invoice->total_taxes) {
                $item1->specifiedLineTradeSettlement->tradeTax[] = $item4tax = new TradeTax();
                $item4tax->typeCode = 'VAT'; //@wvtodo
                $item4tax->categoryCode = 'E'; //@wvtodo tax name? E or S?
                $item4tax->rateApplicablePercent = 0;
            }

            $item1->specifiedLineTradeSettlement->monetarySummation = TradeSettlementLineMonetarySummation::create(number_format($itemTaxable, 2, '.', '')); //@todo what is SpecifiedTradeSettlementLineMonetarySummation?
        }

        $xInvoice->supplyChainTradeTransaction->applicableHeaderTradeAgreement = new HeaderTradeAgreement();
        //888-80140WV01-64

        $leitwegIdField = $this->getCustomValueIdByLabel('xRechnung Leitweg-ID|single_line_text', 'client');

        $leitwegId = $client[$leitwegIdField];

        $xInvoice->supplyChainTradeTransaction->applicableHeaderTradeAgreement->buyerReference = $leitwegId; //$client->id_number;

        $xInvoice->supplyChainTradeTransaction->applicableHeaderTradeAgreement->sellerTradeParty = $sellerTradeParty = new TradeParty();

        if ($company->settings->id_number) {
            $sellerTradeParty->globalID[] = Id::create($company->settings->id_number, '0021'); //@wvtodo GlobalID ??
        }

        $sellerTradeParty->name = $company->present()->name;
        $sellerTradeParty->definedTradeContact = new TradeContact();
        $sellerTradeParty->definedTradeContact->personName = 'Admin'; //Admin?
        $sellerTradeParty->definedTradeContact->telephoneUniversalCommunication = new UniversalCommunication();
        $sellerTradeParty->definedTradeContact->telephoneUniversalCommunication->completeNumber = $company->settings->phone; //@wvtodo
        $sellerTradeParty->definedTradeContact->emailURIUniversalCommunication = new UniversalCommunication();
        $sellerTradeParty->definedTradeContact->emailURIUniversalCommunication->uriid = Id::create($user->email);

        $sellerTradeParty->postalTradeAddress = new TradeAddress();
        $sellerTradeParty->postalTradeAddress->postcode = $company->settings->postal_code;
        $sellerTradeParty->postalTradeAddress->lineOne = $company->settings->address1;
        $sellerTradeParty->postalTradeAddress->city = $company->settings->city;
        $sellerTradeParty->postalTradeAddress->countryCode = 'DE'; //@wvchange error

        //$sellerTradeParty->taxRegistrations[] = TaxRegistration::create('201/113/40209', 'FC');
        $sellerTradeParty->taxRegistrations[] = TaxRegistration::create($company->settings->vat_number, 'VA');//@wvtodo TaxRegistration vat number?

        $xInvoice->supplyChainTradeTransaction->applicableHeaderTradeAgreement->buyerTradeParty = $buyerTradeParty = new TradeParty();

        if ($client->id_number) {
            $buyerTradeParty->id = Id::create($client->id_number); //BuyerReference
        }

        $buyerTradeParty->name = $client->name;

        $buyerTradeParty->postalTradeAddress = new TradeAddress();
        $buyerTradeParty->postalTradeAddress->postcode = $client->postal_code;
        $buyerTradeParty->postalTradeAddress->lineOne = $client->address1;
        $buyerTradeParty->postalTradeAddress->city = $client->city;
        $buyerTradeParty->postalTradeAddress->countryCode = $client->country->iso_3166_2;

        //$xInvoice->supplyChainTradeTransaction->applicableHeaderTradeAgreement->specifiedProcuringProject = ProcuringProject::create('1234', 'Projekt');

        $xInvoice->supplyChainTradeTransaction->applicableHeaderTradeAgreement->buyerOrderReferencedDocument = (new ReferencedDocument())->create($invoice->po_number);//@wvtodo IssuerAssignedID? checkfix

        $xInvoice->supplyChainTradeTransaction->applicableHeaderTradeDelivery = new HeaderTradeDelivery();
        $xInvoice->supplyChainTradeTransaction->applicableHeaderTradeDelivery->shipToTradeParty = $buyerTradeParty;
        $xInvoice->supplyChainTradeTransaction->applicableHeaderTradeDelivery->chainEvent = new SupplyChainEvent();
        $xInvoice->supplyChainTradeTransaction->applicableHeaderTradeDelivery->chainEvent->date = DateTime::create(102, (new \DateTime($invoice->date))->format('Ymd'));//@todo ApplicableHeaderTradeDelivery > ActualDeliverySupplyChainEvent > OccurrenceDateTime > DateTimeString
      //  $invoice->exchangedDocument-> = DateTime::create(102, '20180305');

        //$xInvoice->supplyChainTradeTransaction->applicableHeaderTradeDelivery->deliveryNoteReferencedDocument = ReferencedDocument::create('123456');
        //$xInvoice->supplyChainTradeTransaction->applicableHeaderTradeDelivery->deliveryNoteReferencedDocument->formattedIssueDateTime = FormattedDateTime::create('102', '20180305');

        $xInvoice->supplyChainTradeTransaction->applicableHeaderTradeSettlement = new HeaderTradeSettlement();
        $xInvoice->supplyChainTradeTransaction->applicableHeaderTradeSettlement->paymentReference = $invoice->number;
        $xInvoice->supplyChainTradeTransaction->applicableHeaderTradeSettlement->currency = 'EUR';
        //$xInvoice->supplyChainTradeTransaction->applicableHeaderTradeSettlement->payeeTradeParty = $sellerTradeParty;

        /**
        $xInvoice->supplyChainTradeTransaction->applicableHeaderTradeSettlement->specifiedLogisticsServiceCharge[] = $logisticsServiceCharge = new ProcuringProject();

        $logisticsServiceCharge->description = 'Versandkosten';
        $logisticsServiceCharge->appliedAmount = Amount::create('0');

        $logisticsServiceCharge->tradeTaxes[] = $shippingTax = new TradeTax();

        $shippingTax->typeCode = 'VAT';
        $shippingTax->categoryCode = 'S';
        $shippingTax->rateApplicablePercent = '19.00';
         * **/

        $xInvoice->supplyChainTradeTransaction->applicableHeaderTradeSettlement->specifiedTradeSettlementPaymentMeans[] = $paymentMeans1 = new TradeSettlementPaymentMeans();

        //SpecifiedTradeSettlementPaymentMeans
        $paymentMeans1->typeCode = '30'; //@wvtodo TypeCode?
        $paymentMeans1->information = 'Rechnung'; //@wvtodo Information?


        $iban = $this->getCustomValueIdByLabel('xRechnung IBANID|single_line_text', 'company');
        $companyAccountName = $this->getCustomValueIdByLabel('xRechnung AccountName|single_line_text', 'company');
        $companyProprietaryId = $this->getCustomValueIdByLabel('xRechnung ProprietaryID|single_line_text', 'company');

        $paymentMeans1->payeePartyCreditorFinancialAccount = new CreditorFinancialAccount();
        $paymentMeans1->payeePartyCreditorFinancialAccount->ibanId = Id::create('');
        $paymentMeans1->payeePartyCreditorFinancialAccount->AccountName = '';
        $paymentMeans1->payeePartyCreditorFinancialAccount->ProprietaryID = '';

        $settingsArray = json_decode(json_encode($company->settings), true);


        if ($iban && $settingsArray[$iban]) {
            $paymentMeans1->payeePartyCreditorFinancialAccount->ibanId = Id::create($settingsArray[$iban]);
        }

        if ($companyProprietaryId && $settingsArray[$companyProprietaryId]) {
            $paymentMeans1->payeePartyCreditorFinancialAccount->ProprietaryID = $settingsArray[$companyProprietaryId];
        }

        if ($companyAccountName && $settingsArray[$companyAccountName]) {
            $paymentMeans1->payeePartyCreditorFinancialAccount->AccountName = $settingsArray[$companyAccountName];
        }

        //$paymentMeans1->payeeSpecifiedCreditorFinancialInstitution = new CreditorFinancialInstitution();
        //$paymentMeans1->payeeSpecifiedCreditorFinancialInstitution->bicId = Id::create('BYLADEM1001');

        $taxratesum = $invoice->tax_rate1 + $invoice->tax_rate2 + $invoice->tax_rate3;

        if ($invoice->tax_name1 || floatval($invoice->tax_rate1)) {
            $xInvoice->supplyChainTradeTransaction->applicableHeaderTradeSettlement->tradeTaxes[] = $headerTax1 = new TradeTax();
            $headerTax1->typeCode =  'VAT';
            $headerTax1->categoryCode = 'S';
            $amount = ($taxable / (100)) * $invoice->tax_rate1;
            $headerTax1->basisAmount = Amount::create(number_format($taxable, 2, '.', ''));
            $headerTax1->calculatedAmount = Amount::create(number_format($amount, 2, '.', ''));
            $headerTax1->rateApplicablePercent = $invoice->tax_rate1;
        }

        if ($invoice->tax_name2 || floatval($invoice->tax_rate2)) {
            $xInvoice->supplyChainTradeTransaction->applicableHeaderTradeSettlement->tradeTaxes[] = $headerTax2 = new TradeTax();
            $headerTax2->typeCode =  'VAT';
            $headerTax2->categoryCode = 'S';
            $amount = ($taxable / (100)) * $invoice->tax_rate2;
            $headerTax2->basisAmount = Amount::create(number_format($taxable, 2, '.', ''));
            $headerTax2->calculatedAmount = Amount::create(number_format($amount, 2, '.', ''));
            $headerTax2->rateApplicablePercent = $invoice->tax_rate2;
        }

        if ($invoice->tax_name3 || floatval($invoice->tax_rate3)) {
            $xInvoice->supplyChainTradeTransaction->applicableHeaderTradeSettlement->tradeTaxes[] = $headerTax3 = new TradeTax();
            $headerTax3->typeCode =  'VAT';
            $headerTax3->categoryCode = 'S';
            $amount = ($taxable / (100)) * $invoice->tax_rate3;
            $headerTax3->basisAmount = Amount::create(number_format($taxable, 2, '.', ''));
            $headerTax3->calculatedAmount = Amount::create(number_format($amount, 2, '.', ''));
            $headerTax3->rateApplicablePercent = $invoice->tax_rate3;
        }

        if (!$invoice->total_taxes) {
            $xInvoice->supplyChainTradeTransaction->applicableHeaderTradeSettlement->tradeTaxes[] = $headerTax4 = new TradeTax();
            $headerTax4->typeCode =  'VAT';
            $headerTax4->categoryCode = 'E';
            $headerTax4->basisAmount = Amount::create(number_format(0, 2, '.', ''));
            $headerTax4->calculatedAmount = Amount::create(number_format(0, 2, '.', ''));
            $headerTax4->rateApplicablePercent = 0;
        }

        $xInvoice->supplyChainTradeTransaction->applicableHeaderTradeSettlement->specifiedTradePaymentTerms[] = $paymentTerms = new TradePaymentTerms();
        $paymentTerms->description = $invoice->terms;

        $xInvoice->supplyChainTradeTransaction->applicableHeaderTradeSettlement->specifiedTradeSettlementHeaderMonetarySummation = $summation = new TradeSettlementHeaderMonetarySummation();
        $summation->lineTotalAmount = Amount::create(number_format($taxable, 2, '.', ''));
        $summation->chargeTotalAmount = Amount::create('0.00');
        $summation->allowanceTotalAmount = Amount::create('0.00');
        $summation->taxBasisTotalAmount[] = Amount::create(number_format($taxable, 2, '.', ''));
        $summation->taxTotalAmount[] = Amount::create(number_format($invoice->total_taxes, 2, '.', ''), 'EUR');
        $summation->grandTotalAmount[] =  Amount::create(number_format($invoice->balance, 2, '.', ''));
        //$summation->totalPrepaidAmount = Amount::create('0.00');
        $summation->duePayableAmount =  Amount::create(number_format($invoice->balance, 2, '.', ''));

        $xml = Builder::create()->transform($xInvoice);

        return $xml;
    }

    /**
     * @param $item
     * @param $invoice_total
     * @return float|int
     */
    private function getItemTaxable($item, $invoice_total)
    {
        $total = $item->quantity * $item->cost;

        if ($this->invoice->discount != 0) {
            if ($this->invoice->is_amount_discount) {
                if ($invoice_total + $this->invoice->discount != 0) {
                    $total -= $invoice_total ? ($total / ($invoice_total + $this->invoice->discount) * $this->invoice->discount) : 0;
                }
            } else {
                $total *= (100 - $this->invoice->discount) / 100;
            }
        }

        if ($item->discount != 0) {
            if ($this->invoice->is_amount_discount) {
                $total -= $item->discount;
            } else {
                $total -= $total * $item->discount / 100;
            }
        }

        return round($total, 2);
    }

    /**
     * @return float|int|mixed
     */
    private function getTaxable()
    {
        $total = 0;

        foreach ($this->invoice->line_items as $item) {
            $line_total = $item->quantity * $item->cost;

            if ($item->discount != 0) {
                if ($this->invoice->is_amount_discount) {
                    $line_total -= $item->discount;
                } else {
                    $line_total -= $line_total * $item->discount / 100;
                }
            }

            $total += $line_total;
        }

        if ($this->invoice->discount > 0) {
            if ($this->invoice->is_amount_discount) {
                $total -= $this->invoice->discount;
            } else {
                $total *= (100 - $this->invoice->discount) / 100;
                $total = round($total, 2);
            }
        }

        if ($this->invoice->custom_surcharge1 && $this->invoice->custom_surcharge_tax1) {
            $total += $this->invoice->custom_surcharge1;
        }

        if ($this->invoice->custom_surcharge2 && $this->invoice->custom_surcharge_tax2) {
            $total += $this->invoice->custom_surcharge2;
        }

        if ($this->invoice->custom_surcharge3 && $this->invoice->custom_surcharge_tax3) {
            $total += $this->invoice->custom_surcharge3;
        }

        if ($this->invoice->custom_surcharge4 && $this->invoice->custom_surcharge_tax4) {
            $total += $this->invoice->custom_surcharge4;
        }

        return $total;
    }

    public function taxAmount($taxable, $rate)
    {
        if ($this->invoice->uses_inclusive_taxes) {
            return round($taxable - ($taxable / (1 + ($rate / 100))), 2);
        } else {
            return round($taxable * ($rate / 100), 2);
        }
    }

    public function costWithDiscount($item)
    {
        $cost = $item->cost;

        if ($item->discount != 0) {
            if ($this->invoice->is_amount_discount) {
                $cost -= $item->discount / $item->quantity;
            } else {
                $cost -= $cost * $item->discount / 100;
            }
        }

        return $cost;
    }

    public function getCustomValueIdByLabel($label, $type) {
        $invoice = $this->invoice;
        $company = $invoice->company;

        foreach ($company->custom_fields as $custom_field => $value) {
            if ($value === $label) {
                if (strpos($custom_field, $type) !== FALSE) {
                    return str_replace($type, 'custom_value', $custom_field);
                }
            }
        }

        return '';
    }
}
