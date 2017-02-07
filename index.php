<?php
require 'vendor/autoload.php';

// Base pubmed URL
$pmurl = 'https://www.ncbi.nlm.nih.gov/pubmed/';

// Initialize constructed query URLs
$articlequery = $citationquery = 'Run Search To Get URL';

// Middleware class to catch redirects:
class EffectiveUrlMiddleware
{
    /**
     * @var \Psr\Http\Message\RequestInterface
     */
    private $lastRequest;

    /**
     * @param \Psr\Http\Message\RequestInterface $request
     *
     * @return \Psr\Http\Message\RequestInterface
     */
    public function __invoke(\Psr\Http\Message\RequestInterface $request)
    {
        $this->lastRequest = $request;
        return $request;
    }

    /**
     * @return \Psr\Http\Message\RequestInterface
     */
    public function getLastRequest()
    {
        return $this->lastRequest;
    }
}

if (isset($_GET['search'])) {
	//Get form vals:
	$db = $_GET["db"];
	$affiliation = $_GET["affiliation"];
	$daterange = preg_replace('/(\d{4}\/\d{2}\/\d{2}) \- (\d{4}\/\d{2}\/\d{2})/', '("$1"[PDAT] : "$2"[PDAT])', $_GET["daterange"]); //format date range for query:

	// Middleware stack to assign to our request OBJ (to catch redirects for constructed URLs)
	$stack = \GuzzleHttp\HandlerStack::create();
	$effectiveYrlMiddleware = new EffectiveUrlMiddleware();
	$stack->push(\GuzzleHttp\Middleware::mapRequest($effectiveYrlMiddleware));

	//Client to connect to PubMed API
	$client = new GuzzleHttp\Client(
		['base_uri' => 'https://eutils.ncbi.nlm.nih.gov'],
		['allow_redirects' => ['track_redirects' => true]]
	);

	//First, retrieve list of PubMed IDs based on our search criteria:
	//$request_url = 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi?db=pubmed&rettype=xml&retmode=uilist&retmax=1000&usehistory=y&term=Oregon+Health+And+Science+University%5BAffiliation%5D+AND+(%222016%2F12%2F01%22%5BPDAT%5D+%3A+%222016%2F12%2F31%22%5BPDAT%5D)';
	$response = $client->request('GET', '/entrez/eutils/esearch.fcgi', [
		'headers' => ['Accept' => 'application/xml'], 
		'query' => [
			'db' => $db,
			'rettype' => 'xml',
			'retmode' => 'uilist',
			'retmax' => '1000', 
			'usehistory' => 'y', 
			'term' => ($affiliation . ' AND ' . $daterange)
		], 
		'timeout' => 120,
		'handler' => $stack,
		\GuzzleHttp\RequestOptions::ALLOW_REDIRECTS => true
	])->getBody()->getContents();

	// get constructed URL for query:
	$articlequery = $effectiveYrlMiddleware->getLastRequest()->getUri()->__toString();

	// get WebEnv and QueryKey values for efetch query:
	$responseXml = simplexml_load_string($response);
	if ($responseXml instanceof \SimpleXMLElement) {
		$webenv = (string)$responseXml->xpath('/eSearchResult/WebEnv')[0];
		$query_key = (string)$responseXml->xpath('/eSearchResult/QueryKey')[0];
		
		if ($webenv != '' && $query_key != '') {
			//Retrieve PubMed Citations:
			//$request_url = 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi?db=pubmed&rettype=xml&retmode=text&webenv=xxx&querykey=x;
			$response = $client->request('GET', '/entrez/eutils/efetch.fcgi', [
				'headers' => ['Accept' => 'application/xml'], 
				'query' => [
					'db' => $db,
					'rettype' => 'xml',
					'retmode' => 'text',
					'webenv' => $webenv,
					'query_key' => $query_key
				], 
				'timeout' => 120,
				'handler' => $stack,
				\GuzzleHttp\RequestOptions::ALLOW_REDIRECTS => true
			])->getBody()->getContents();

			// get constructed URL for query:
			$citationquery = $effectiveYrlMiddleware->getLastRequest()->getUri()->__toString();

			// prepare data for output:
			$responseXml = simplexml_load_string($response);

			//echo '<pre>';
			//print_r($responseXml);
			//echo '</pre>';
		}
	}
};

?>
<html>
<head>
<title>PubMed Date Query Tool</title>

<!-- Include Required Prerequisites -->
<script type="text/javascript" src="//cdn.jsdelivr.net/jquery/1/jquery.min.js"></script>
<script type="text/javascript" src="//cdn.jsdelivr.net/momentjs/latest/moment.min.js"></script>
<link rel="stylesheet" type="text/css" href="//cdn.jsdelivr.net/bootstrap/3/css/bootstrap.css" />
 
<!-- Include Date Range Picker and DataTables Bootstrap: -->
<script type="text/javascript" src="//cdn.jsdelivr.net/bootstrap.daterangepicker/2/daterangepicker.js"></script>
<link rel="stylesheet" type="text/css" href="//cdn.jsdelivr.net/bootstrap.daterangepicker/2/daterangepicker.css" />
<link href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css" rel="stylesheet"/>
<link href="https://cdnjs.cloudflare.com/ajax/libs/datatables/1.10.12/css/dataTables.bootstrap.min.css" rel="stylesheet"/>
<link href="https://cdn.datatables.net/buttons/1.2.4/css/buttons.bootstrap.min.css rel="stylesheet"/>

<!-- Local CSS stlying -->
<link rel="stylesheet" type="text/css" href="style.css" />

</head>
<body>

<form class="form-horizontal">
<fieldset>

<!-- Form Name -->
<legend>PubMed Query Test</legend>

<!-- Text input-->
<div class="form-group">
  <label class="col-md-4 control-label" for="db">db</label>  
  <div class="col-md-4">
  <input id="db" name="db" type="text" value="pubmed" placeholder="pubmed" class="form-control input-md" required="">
  <span class="help-block">PubMed DB to Search (<a href="https://www.ncbi.nlm.nih.gov/books/NBK25497/#chapter2.chapter2_table1" target="_blank">more info</a>)</span>  
  </div>
</div>

<!-- Search input-->
<div class="form-group">
  <label class="col-md-4 control-label" for="affiliation">affiliation</label>
  <div class="col-md-4">
    <input id="affiliation" name="affiliation" type="text" value="Oregon Health And Science University[AD]" placeholder="Oregon Health And Science University[Affiliation]" class="form-control input-md" required="">
    <p class="help-block">Affiliation Term for Search (<a href="https://www.nlm.nih.gov/bsd/mms/medlineelements.html#ad" target="_blank">more info</a>)</p>
  </div>
</div>

<!-- Date range-->
<div class="form-group">
  <label class="col-md-4 control-label" for="term">date-range</label>
  <div class="col-md-4">
    <input type="text" name="daterange" value='2017/01/01 - 2017/01/31' class="form-control input-md" required="" />
    <p class="help-block">Publication Date Range for Search</p>
  </div>
</div>

<script type="text/javascript">
$(function() {
    $('input[name="daterange"]').daterangepicker({
        locale: {
            format: 'YYYY/MM/DD'
        }
    });
});
</script>

<!-- Button -->
<div class="form-group">
  <label class="col-md-4 control-label" for="search">Go</label>
  <div class="col-md-4">
    <button id="search" name="search" class="btn btn-primary">Search</button>
  </div>
</div>

<!-- Textarea -->
<div class="form-group">
  <label class="col-md-4 control-label" for="articlequery">Article ID Query URL</label>
  <div class="col-md-4">                     
    <textarea class="form-control" id="articlequery" name="articlequery"><?php echo $articlequery;?></textarea>
  </div>
</div>

<!-- Textarea -->
<div class="form-group">
  <label class="col-md-4 control-label" for="citationquery">Citation Query URL</label>
  <div class="col-md-4">                     
    <textarea class="form-control" id="citationquery" name="citationquery"><?php echo $citationquery;?></textarea>
  </div>
</div>

</fieldset>

</form>

<hr>

<div class="container" style="width: 100%">
<h1>Search Results</h1>
<table id="results" class="table table-striped table-bordered table-hover" cellspacing="0" width="100%">
<thead>
	<tr> <th>PID</th><th>Pub Date</th><th>Journal</th><th>Article</th><th>Abstract</th></tr>
</thead>
<tbody>
<?php
if (isset($_GET['search'])) {
	foreach($responseXml->PubmedArticle as $article) {
		echo '<tr> ';
		echo '<td><a href="' . $pmurl . (string)$article->MedlineCitation->PMID . '" target="_blank">' . (string)$article->MedlineCitation->PMID . '</a></td>';
		echo '<td>' . (string)$article->MedlineCitation->Article->Journal->JournalIssue->PubDate->Month . "-" . (string)$article->MedlineCitation->Article->Journal->JournalIssue->PubDate->Day . "-" . (string)$article->MedlineCitation->Article->Journal->JournalIssue->PubDate->Year . '</td>';
		echo '<td>' . (string)$article->MedlineCitation->Article->Journal->Title . '</td>';
		echo '<td>' . (string)$article->MedlineCitation->Article->ArticleTitle . '</td>';
		echo '<td>' . (string)$article->MedlineCitation->Article->Abstract->AbstractText . '</td>';
		echo '</tr>';
	}
}
?>
</tbody>
</table>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.1.1/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/datatables/1.10.12/js/jquery.dataTables.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/datatables/1.10.12/js/dataTables.bootstrap.min.js"></script>
<script src="https://cdn.datatables.net/buttons/1.2.4/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/1.2.4/js/buttons.bootstrap.min.js"></script>
<script src="//cdnjs.cloudflare.com/ajax/libs/jszip/2.5.0/jszip.min.js"></script>
<script src="//cdn.rawgit.com/bpampuch/pdfmake/0.1.18/build/pdfmake.min.js"></script>
<script src="//cdn.rawgit.com/bpampuch/pdfmake/0.1.18/build/vfs_fonts.js"></script>
<script src="//cdn.datatables.net/buttons/1.2.4/js/buttons.html5.min.js"></script>
<script src="//cdn.datatables.net/buttons/1.2.4/js/buttons.print.min.js"></script>
<script src="//cdn.datatables.net/buttons/1.2.4/js/buttons.colVis.min.js"></script>
<script type="text/javascript" src="//cdn.jsdelivr.net/bootstrap.daterangepicker/2/daterangepicker.js"></script>
<script type="text/javascript">
$(document).ready(function() {
    var table = $('#results').DataTable( {
        lengthChange: true,
        buttons: [ 'copy', 'excel', 'colvis' ],
        "lengthMenu": [[10, 25, 50, -1], [10, 25, 50, "All"]]
    } );
 
    table.buttons().container()
        .appendTo( '#results_wrapper .col-sm-6:eq(0)' );
} );
</script>

</body>
</html>