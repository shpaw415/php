<?php
include_once CLASS_PATH . '/request.class.php';

enum Provinces:string {
    case Quebec = 'QC';
    case Ontario = 'ON';
    case Manitoba = 'MB';
    case Alberta = 'AB';
    case BritishColumbia = 'BC';
    case all = 'all';
}

class TaxesManager
{

    private $data = [
        'CA' => [
            'url' => 'https://api.salestaxapi.ca',
        ],
    ];
    private $request;
    private $taxes;
    public $returnMsg = '';

    private function ApiCanadaTaxes(Provinces $province = Provinces::all)
    {
        $this->request = new Requests();

        $url = $this->data['CA']['url'];
        $this->request->Get($url . '/v2/province/'.$province->value, [
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:78.0) Gecko/20100101 Firefox/78.0',
        ]);
        $this->taxes = json_decode($this->request->GetData(), true);
        return $this->taxes;
    }
    public static function GetTaxes(Provinces $province = Provinces::all): TaxesManager {
        $taxesManager = new TaxesManager();
        $taxesManager->ApiCanadaTaxes($province);
        return $taxesManager;
        
    }
    public function AddTaxes(int|float $amount):float
    {
        if ($this->taxes == null) {
            $this->returnMsg = 'Taxes not loaded';
            return false;
        }
        $provinceTaxes = $this->taxes['pst'];
        $federalTaxes = $this->taxes['gst'] + $this->taxes['hst'];

        return round($amount + ceil($amount * $provinceTaxes) + ceil($amount * $federalTaxes), 2);
    }
    /**
     * Returns ['province' => float, 'federal' => float]
     */
    public function formatTaxes(): array {
        return [
            'province' => $this->taxes['pst'],
            'federal' => $this->taxes['gst'] + $this->taxes['hst']
        ];
    }
}
