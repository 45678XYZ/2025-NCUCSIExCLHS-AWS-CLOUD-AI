<?php
/**
* Plugin Name: AWS_comprehend
* Author: YUE YUE
* Version: 1.0.0
*/
if (!defined('ABSPATH')) {
   exit;
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

use WP_REST_Response;
use Aws\Comprehend\ComprehendClient;

add_action('wp_enqueue_scripts', 'comprehend_load_assets');
add_shortcode('comprehend-form', 'comprehend_text_form');
add_action('wp_footer', 'comprehend_load_scripts');
add_action('rest_api_init', 'comprehend_register_rest_api');
/**
* 載入前端資源 (Bootstrap + jQuery)
*/
function comprehend_load_assets()
{
   wp_enqueue_style(
       'bootstrap-css',
       'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css',
       array(),
       '5.3.0',
       'all'
   );

   wp_enqueue_script('jquery');

   wp_enqueue_script(
       'bootstrap-js',
       'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js',
       array('jquery'),
       '5.3.0',
       true
   );
}

/**
* 顯示 AWS Comprehend 表單
*/
function comprehend_text_form()
{
   ob_start();
   ?>
   <div class="container mt-5">
       <h1>AWS Comprehend Form</h1>
       <p> Please choose the service</p>
       <form id="aws-comprehend-form__form">
           <div class="form-group mb-2">
               <select class="form-select form-control" name="comprehend_service">
                   <option value="Sentiment Analysis">Sentiment Analysis（情感分析）</option>
                   <option value="Syntax Analysis">Syntax Analysis（句法分析）</option>
                   <option value="Entity Analysis">Entity Analysis（實體辨識）</option>
               </select>
           </div>
           <div class="form-group mb-2 form-floating">
               <textarea class="form-control" name="text_to_be_analysed" placeholder="Leave a comment here" id="floatingTextarea" style="height: 200px"></textarea>
               <label for="floatingTextarea">請輸入要分析文字</label>
           </div>
           <div class="form-group">
               <button class="btn btn-success btn-block" type="submit">Send</button>
           </div>
       </form>
       <div id="result-message" class="alert" style="display:none;"></div>
   </div>
   <?php
   return ob_get_clean();
}

/**
* 載入前端 AJAX JS 代碼
*/
function comprehend_load_scripts()
{
   ?>
   <script>
       var nonce = '<?php echo wp_create_nonce('wp_rest'); ?>';
       (function ($) {
           $('#aws-comprehend-form__form').off('submit').submit(function (event) {
               event.preventDefault();
               var form = $(this).serialize();

               $('#aws-comprehend-form__form').find('button').prop('disabled', true);
               $('#aws-comprehend-form__form').find('button').text('處理中...');
               $('#result-message').removeClass('alert-success alert-danger').addClass('alert-info').text('處理中...').show();

               $.ajax({
                   method: 'post',
                   url: '<?php echo esc_url(get_rest_url(null, 'comprehend-form/v1/send-api-to-aws')); ?>',
                   headers: {'X-WP-Nonce': nonce},
                   data: form,
                   success: function (response) {
                       $('#aws-comprehend-form__form').find('button').prop('disabled', false);
                       $('#aws-comprehend-form__form').find('button').text('送出');
                       $('#result-message').removeClass('alert-info alert-danger').addClass('alert-success').html(response.comprehend_output).show();
                   },
                   error: function (xhr, status, error) {
                       $('#aws-comprehend-form__form').find('button').prop('disabled', false);
                       $('#aws-comprehend-form__form').find('button').text('送出');
                       $('#result-message').removeClass('alert-info alert-success').addClass('alert-danger').text('❌ 發生錯誤：' + error).show();
                       console.log('AJAX Error:', error);
                       console.log('Response:', xhr.responseText);
                   }
               });
           });
       })(jQuery);
   </script>
   <?php
}

/**
* 註冊 REST API 路由
*/
function comprehend_register_rest_api()
{
   register_rest_route('comprehend-form/v1', 'send-api-to-aws', array(
       'methods' => 'POST',
       'callback' => 'comprehend_handle_form',
       'permission_callback' => function () {
           return true; // 可自行增加權限判斷
       }
   ));
}

/**
* REST API 處理函式
*/
function comprehend_handle_form(WP_REST_Request $request)
{
   if (!wp_verify_nonce($request->get_header('x-wp-nonce'), 'wp_rest')) {
       return new WP_REST_Response(['message' => '驗證失敗'], 422);
   }

   $params = $request->get_params();
   $text = sanitize_text_field($params['text_to_be_analysed'] ?? '');
   $service = sanitize_text_field($params['comprehend_service'] ?? '');

   if (empty($text)) {
       return new WP_REST_Response(['message' => '請輸入文字'], 422);
   }

   try {
       $result = comprehend_call_aws($text, $service);
   } catch (Exception $e) {
       return new WP_REST_Response(['message' => 'AWS API錯誤: ' . $e->getMessage()], 500);
   }

   return new WP_REST_Response([
       'message' => '提交成功',
       'comprehend_service' => $service,
       'comprehend_output' => $result
   ], 200);
}

/**
* 呼叫 AWS Comprehend API
*/
function comprehend_call_aws($text, $service)
{
   $client = comprehend_get_aws_client();

   if (!$client) {
       throw new Exception('AWS 客戶端初始化失敗');
   }

   switch ($service) {
       case 'Sentiment Analysis':
           $result = $client->detectSentiment([
               'LanguageCode' => 'zh-TW',
               'Text' => $text,
           ]);
           return comprehend_generate_sentiment_html([
               'sentiment' => $result['Sentiment'],
               'scores' => $result['SentimentScore'],
           ]);

       case 'Syntax Analysis':
           $result = $client->detectSyntax([
               'LanguageCode' => 'en',
               'Text' => $text,
           ]);
           return comprehend_generate_syntax_html(['tokens' => $result['SyntaxTokens']]);

       case 'Entity Analysis':
           $result = $client->detectEntities([
               'LanguageCode' => 'zh-TW',
               'Text' => $text,
           ]);
           return comprehend_generate_entity_html(['entities' => $result['Entities']]);

       default:
           throw new Exception('未知的服務類型');
   }
}

/**
* 初始化 AWS ComprehendClient
*/
function comprehend_get_aws_client()
{
   $region = 'us-west-2';
   $accessKeyId = ''; // 輸入AccessKey
   $secretAccessKey = ''; // 輸入SecretKey

   try {
       $client = new ComprehendClient([
           'region' => $region,
           'version' => 'latest',
           'credentials' => [
               'key' => $accessKeyId,
               'secret' => $secretAccessKey,
           ],
           'http' => [
               'timeout' => 10,
               'connect_timeout' => 5,
           ],
       ]);
       return $client;
   } catch (Exception $e) {
       error_log('AWS SDK 初始化錯誤: ' . $e->getMessage());
       return null;
   }
}

/**
* 產生情感分析結果 HTML
*/
function comprehend_generate_sentiment_html($result)
{
   $sentiment = esc_html($result['sentiment']);
   $html = '<div class="sentiment-analysis-result">';
   $html .= '<h4>情感分析結果</h4>';
   $html .= '<p>情感分析結果是: <strong>' . $sentiment . '</strong></p>';

   if (isset($result['scores'])) {
       $html .= '<table class="table table-striped table-bordered">';
       $html .= '<thead><tr><th>情感類型</th><th>可信度</th></tr></thead>';
       $html .= '<tbody>';
       foreach ($result['scores'] as $type => $score) {
           $html .= '<tr>';
           $html .= '<td>' . esc_html($type) . '</td>';
           $html .= '<td>' . round($score * 100, 1) . '%</td>';
           $html .= '</tr>';
       }
       $html .= '</tbody></table>';
   }
   $html .= '</div>';
   return $html;
}

/**
* 產生句法分析結果 HTML
*/
function comprehend_generate_syntax_html($result)
{
   $tokens = $result['tokens'];
   $html = '<div class="syntax-analysis-result">';
   $html .= '<h4>語法分析結果</h4>';

   if (empty($tokens)) {
       $html .= '<p>未檢測到任何語法成分。</p>';
   } else {
       $html .= '<table class="table table-striped table-bordered">';
       $html .= '<thead><tr><th>詞語</th><th>詞性</th><th>可信度</th><th>起始位置</th><th>結束位置</th></tr></thead><tbody>';
       foreach ($tokens as $token) {
           $text = esc_html($token['Text']);
           $pos = esc_html($token['PartOfSpeech']['Tag']);
           $score = isset($token['PartOfSpeech']['Score']) ? round($token['PartOfSpeech']['Score'] * 100, 1) . '%' : 'N/A';
           $begin = $token['BeginOffset'];
           $end = $token['EndOffset'];

           $html .= '<tr>';
           $html .= "<td>$text</td><td>$pos</td><td>$score</td><td>$begin</td><td>$end</td>";
           $html .= '</tr>';
       }
       $html .= '</tbody></table>';
   }
   $html .= '</div>';
   return $html;
}

/**
* 產生實體分析結果 HTML
*/
function comprehend_generate_entity_html($result)
{
   $entities = $result['entities'];
   $html = '<div class="entity-analysis-result">';
   $html .= '<h4>實體分析結果</h4>';

   if (empty($entities)) {
       $html .= '<p>未檢測到任何實體。</p>';
   } else {
       $html .= '<table class="table table-striped table-bordered">';
       $html .= '<thead><tr><th>實體</th><th>類型</th><th>可信度</th><th>開始位置</th><th>結束位置</th></tr></thead><tbody>';
       foreach ($entities as $entity) {
           $text = esc_html($entity['Text']);
           $type = esc_html($entity['Type']);
           $score = round($entity['Score'] * 100, 1) . '%';
           $begin = $entity['BeginOffset'];
           $end = $entity['EndOffset'];

           $html .= '<tr>';
           $html .= "<td>$text</td><td>$type</td><td>$score</td><td>$begin</td><td>$end</td>";
           $html .= '</tr>';
       }
       $html .= '</tbody></table>';
   }
   $html .= '</div>';
   return $html;
}
