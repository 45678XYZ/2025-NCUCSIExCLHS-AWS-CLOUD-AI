<?php
/**
* Plugin Name: Textract 
* Author: ChenChunWei
* Version: 2.0.0
*/

// 防止檔案被直接存取
if (!defined('ABSPATH')) {
    exit;
}

// Load AWS SDK
require_once dirname(__DIR__) . '/vendor/autoload.php';

// 引用等等會用到的功能
use Aws\Textract\TextractClient;
use Aws\S3\S3Client;

// 檔案上傳至S3
function upload_to_s3($file_data, $bucket, $key, $region) {

    // 建立客戶端
    $s3 = new S3Client([
        'version' => 'latest',
        'region' => $region,
    ]);
 
    try {
 
        // 將檔案存到S3的指定路徑
        $result = $s3->putObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'Body' => $file_data,
        ]);
 
    } catch (Exception $e) { // 錯誤處理
 
        return "Error uploading to S3: " . $e->getMessage();
 
    }
 
    // 返回公開網址  
    return $result['ObjectURL']; 
 }

// function1: 建立上傳表單
function textract_upload_form() {

    // 設定表單提交的目標網址
    $action_url = esc_url(admin_url('admin-post.php'));

    // 返回要上傳的表單(HTML)
    return '<form action="' . $action_url . '" method="post" enctype="multipart/form-data" onsubmit="showLoading()">
                <input type="hidden" name="action" value="textract_upload">
                <input type="file" name="textract_file" required>
                <button type="submit">Upload and Analyze</button>
            </form>
            <p id="loadingMessage" style="display:none; color: red;">Processing... Please wait.</p>
            <script>
                function showLoading() {
                    document.getElementById("loadingMessage").style.display = "block";
                }
            </script>';  

}

// function2: 檔案分析（改良版）
function textract_upload_and_analyze($file) {

    $region = 'us-west-2';  // 地區

    // 確認傳入的檔案內容是否為空
    if (!isset($file['tmp_name']) || empty($file['tmp_name'])) {
        return "No file uploaded.";
    }

    $bucket = "textract-bucket-name" ; // bucket名稱
    $key = "uploads/" . basename($file['name']); // S3檔案路徑
    $fileType = mime_content_type($file['tmp_name']); // 檔案類型

    // 讀入檔案
    $file_data = file_get_contents($file['tmp_name']);

    // 上傳檔案到S3
    $s3_url = upload_to_s3($file_data, $bucket, $key, $region);

    // 建立Textract客戶端
    $textract = new TextractClient([
        'version' => 'latest',
        'region' => $region
    ]);

    try {

        // 若檔案為PDF的分析方式
        if ($fileType == "application/pdf") {

            // 呼叫API進行分析
            $result = $textract->startDocumentTextDetection([
                'DocumentLocation' => [
                    'S3Object' => [
                        'Bucket' => $bucket,
                        'Name' => $key,
                    ],
                ],
            ]);

            // 確認分析狀態
            $jobStatus = '';
            do {

                sleep(1); // 每一秒鐘檢查一次
                $statusResult = $textract->getDocumentTextDetection([
                    'JobId' => $result['JobId'],
                ]);
                $jobStatus = $statusResult['JobStatus'];

            } while ($jobStatus == 'IN_PROGRESS');

            // 分析結束，若成功則提取分析結果並回傳
            if ($jobStatus == 'SUCCEEDED') {

                $extractedText = ''; // 用於儲存檔案的分析狀態的變數
         
                // 提取出所需的資訊（屬性為LINE的物件）
                foreach ($statusResult["Blocks"] as $block) {
                    if ($block["BlockType"] == "LINE") {
                        $extractedText .= $block["Text"] . " ";
                    }
                }

                // 把結果存到 Wordpress Option
                update_option('textract_last_text', $extractedText);
         
                // 回傳成功訊息
                return "Success! Text extracted from PDF.";

            } else { // 若分析沒有成功，回傳錯誤訊息

                update_option('textract_last_text', 'Failed with status ' . $jobStatus);
                return "Textract job failed with status: " . $jobStatus;

            }

        } else { // 其他檔案類型的分析方式

            // 呼叫Textract的API來分析，並把分析結果存進$result
            $result = $textract->detectDocumentText([
                'Document' => [
                    'S3Object' => [
                        'Bucket' => $bucket,
                        'Name' => $key,
                    ],
                ],
            ]);

            $extractedText = ""; // 用於儲存檔案的分析狀態的變數

            // 提取出所需的資訊（屬性為LINE的物件）
            foreach ($result["Blocks"] as $block) {
                if ($block["BlockType"] == "LINE") {
                    $extractedText .= $block["Text"] . " ";
                }
            }

            // 把結果存到 Wordpress Option
            update_option('textract_last_text', $extractedText);
 
            // 回傳成功訊息
            return "Success! Text extracted.";
        }

    } catch (Exception $e) { // 錯誤處理

        return "Textract error: " . $e->getMessage();

    }
}

// function3: 檔案處理
function textract_handle_upload() {

    // 檢查檔案
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['textract_file'])) {
    
        $max_file_size = 5 * 1024 * 1024; // 設定檔案大小上限
        
        if ($_FILES['textract_file']['size'] > $max_file_size) { // 檢查檔案大小
        
            update_option('textract_last_text', 'Error: File size exceeds the 5MB limit.');
            wp_redirect($_SERVER["HTTP_REFERER"]);
            exit;
            
        }
        
        textract_upload_and_analyze($_FILES['textract_file']); // 進行分析
    }
    
    wp_redirect($_SERVER["HTTP_REFERER"]); // 重新導回頁面
    exit; // 結束程式
    
}

// function4: 顯示結果
function textract_display_shortcode() {
    
    // 取得分析結果
    $extractedText = get_option('textract_last_text', 'No text extracted yet.');

    // 返回要上傳的文字框(HTML) 
    return "<div class='aws-textract-output' style='border: 1px solid #ccc; padding: 10px;'>
                <strong>Extracted Text:</strong>
                <p>{$extractedText}</p>
            </div>";

}

// 清除表單內容
function clear_textract_results() {

    update_option('textract_last_text', 'No text extracted yet.');
    
 }
 
add_shortcode('textract_upload_form', 'textract_upload_form');
add_shortcode('textract_result', 'textract_display_shortcode');
add_action('admin_post_textract_upload', 'textract_handle_upload');
add_action('admin_post_nopriv_textract_upload', 'textract_handle_upload');
add_action('wp_head', 'clear_textract_results');

?>
