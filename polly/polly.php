<?php
/**
 * Plugin Name: AWS_polly
 * Author: Yungtunchi
 * Version: 1.0.0
 */
if (!defined('ABSPATH')) {
    exit;
}
require_once dirname(__DIR__) . '/vendor/autoload.php';


add_action('wp_enqueue_scripts','polly_load_assets');
function polly_load_assets(){
    wp_enqueue_style(
        'bootstrap-css',
        'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css',
        array(),
        '5.3.0',
        'all'
    );
    // 載入 jQuery（WordPress 內建 jQuery）
    wp_enqueue_script('jquery');

    // 載入 Bootstrap JS
    wp_enqueue_script(
        'bootstrap-js',
        'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js',
        array('jquery'),
        '5.3.0',
        true
    );
}
use Aws\Polly\PollyClient;
use Aws\Exception\AwsException;


//  產生 Shortcode，讓前端顯示表單，"shortcode 輸入[wp_polly]"
function wp_polly_form() {
    ob_start();
    ?>
     <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h2 class="card-title text-center mb-4">輸入文字轉語音</h2>
                        <div class="mb-3">
                            <textarea id="polly-text" class="form-control" rows="4" placeholder="輸入要轉換的文字..."></textarea>
                        </div>
                        <div class="d-grid gap-2">
                            <button id="polly-convert" class="btn btn-primary">轉換為語音</button>
                        </div>
                        <audio id="polly-audio" controls style="display:none; width: 100%; margin-top: 15px;"></audio>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        jQuery(document).ready(function($) {
            $("#polly-convert").click(function() {
                var text = $("#polly-text").val();
                if (text.trim() === "") {
                    alert("請輸入文字！");
                    return;
                }

                $.ajax({
                    url: "<?php echo admin_url('admin-ajax.php'); ?>",
                    type: "POST",
                    data: { action: "wp_polly_synthesize", text: text },
                    success: function(response) {
                        if (response.success) {
                            $("#polly-audio").attr("src", response.audio_url).show();
                            $("#polly-audio")[0].play();
                        } else {
                            alert("語音轉換失敗：" + response.message);
                        }
                    },
                    error: function() {
                        alert("請求失敗，請檢查伺服器！");
                    }
                });
            });
        });
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('wp_polly', 'wp_polly_form');


// WordPress AJAX 處理 Polly 轉語音
function wp_polly_synthesize() {
    if (!isset($_POST['text']) || empty(trim($_POST['text']))) {
        wp_send_json(["success" => false, "message" => "請輸入文字"]);
    }

    $voice_id_options = array(
        'Zhiyu (Chinese Female)' => 'Zhiyu',
        'Joanna (English US Female)' => 'Joanna',
        'Matthew (English US Male)' => 'Matthew',
        'Lupe (Spanish Female)' => 'Lupe',
        // Add more options as needed
    );


    $authentication_method = 'api_key';
    $region        = 'us-west-2';  // Default region
    $accessKeyId   = ''; // 輸入AccessKey
    $secretAccessKey = ''; // 輸入SecretKey
    $VoiceId = $voice_id_options['Zhiyu (Chinese Female)'];
    //要轉換的文字
    $text = trim($_POST['text']);
    error_log($text);
    //  建立 AWS Polly 物件
    $polly = new PollyClient([
        'region' => $region,
        'version' => 'latest',
        'credentials' => [
            'key'    => $accessKeyId,
            'secret' => $secretAccessKey,
        ]
    ]);

    try {
        //  Polly 轉語音 (可中文、英文)
        $result = $polly->synthesizeSpeech([
            'Text'         => $text,
            'OutputFormat' => 'mp3',
            'VoiceId'      => $VoiceId,   //女聲
        ]);

        //  取得音檔內容
        $audioStream = $result['AudioStream']->getContents();

        //  使用 WordPress 內建上傳目錄
        $upload_dir = wp_upload_dir();
        // error_log(print_r($upload_dir, true));
        $file_path = $upload_dir['path'] . '/polly_audio_' . time() . '.mp3';
        file_put_contents($file_path, $audioStream);

        //  取得公開 URL
        error_log("Polly success");
        $audio_url = str_replace('http://', 'https://', $upload_dir['url']) . '/' . basename($file_path);
        wp_send_json(["success" => true, "audio_url" => $audio_url]);

    } catch (AwsException $e) {
        error_log($e);
        wp_send_json(["success" => false, "message" => $e->getMessage()]);
    }
}
add_action('wp_ajax_wp_polly_synthesize', 'wp_polly_synthesize');
add_action('wp_ajax_nopriv_wp_polly_synthesize', 'wp_polly_synthesize');
?>
