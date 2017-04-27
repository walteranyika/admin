<?php
require_once(realpath(dirname(__FILE__) . "/../tools/rest.php"));

class ProductOrder extends REST{
	
	private $mysqli = NULL;
	private $db = NULL;
    private $product_order_detail	= NULL;
	
	public function __construct($db) {
		parent::__construct();
		$this->db = $db;
		$this->mysqli = $db->mysqli;
        $this->product_order_detail = new ProductOrderDetail($this->db);
    }
	
	public function findAll(){
		if($this->get_request_method() != "GET") $this->response('',406); 
		$query="SELECT * FROM product_order po ORDER BY po.id DESC";
		$this->show_response($this->db->get_list($query));
	}


    /*
    *Counting Number of records
    */
    public function countRecords(){
        if($this->get_request_method() != "GET") $this->response('',406); 
        $query="SELECT count(*) as count FROM product_order";
        $this->show_response($this->db->getRecordCount($query));
    }

    public function findOne(){
        if($this->get_request_method() != "GET") $this->response('',406);
        if(!isset($this->_request['id'])) $this->responseInvalidParam();
        $id = (int)$this->_request['id'];
        $query="SELECT distinct * FROM product_order po WHERE po.id=$id";
        $this->show_response($this->db->get_one($query));
    }
	
	public function findAllByPage(){
		if($this->get_request_method() != "GET") $this->response('',406);
		if(!isset($this->_request['limit']) || !isset($this->_request['page']))$this->responseInvalidParam();
		$limit = (int)$this->_request['limit'];
		$offset = ((int)$this->_request['page']) - 1;
		$q = (isset($this->_request['q'])) ? ($this->_request['q']) : "";
        if($q != ""){
            $query=	"SELECT DISTINCT * FROM product_order po "
                    ."WHERE buyer REGEXP '$q' OR code REGEXP '$q' OR address REGEXP '$q' OR email REGEXP '$q' OR phone REGEXP '$q' OR comment REGEXP '$q' OR shipping REGEXP '$q' "
                    ."ORDER BY po.id DESC LIMIT $limit OFFSET $offset";
        } else {
		    $query="SELECT DISTINCT * FROM product_order po ORDER BY po.id DESC LIMIT $limit OFFSET $offset";
        }
		$this->show_response($this->db->get_list($query));
	}
	//count all records
	public function allCount(){
		if($this->get_request_method() != "GET") $this->response('',406);
		$query="SELECT COUNT(DISTINCT po.id) FROM product_order po";
		$this->show_response_plain($this->db->get_count($query));
	}

    public function insertOne(){
        if($this->get_request_method() != "POST") $this->response('', 406);
        $data = json_decode(file_get_contents("php://input"), true);
        if(!isset($data)) $this->responseInvalidParam();
        $resp = $this->insertOnePlain($data);
        $this->show_response($resp);
    }

    public function insertOnePlain($data){
        $column_names = array('code', 'buyer', 'address', 'email', 'shipping', 'date_ship', 'phone', 'comment', 'status', 'total_fees', 'tax', 'created_at', 'last_update','reg_id');
        $table_name = 'product_order';
        $pk = 'id';
        $data['code'] = $this->getRandomCode();
        $resp = $this->db->post_one($data, $pk, $column_names, $table_name);
        return $resp;
    }


    public function updateOne(){
        if($this->get_request_method() != "POST") $this->response('',406);
        $data = json_decode(file_get_contents("php://input"),true);
        if(!isset($data['id'])) $this->responseInvalidParam();
        $id = (int)$data['id'];
        $column_names = array('buyer', 'address', 'email', 'shipping', 'date_ship', 'phone', 'comment', 'status', 'total_fees', 'tax', 'created_at', 'last_update');
        $table_name = 'product_order';
        $pk = 'id';
        $this->show_response($this->db->post_update($id, $data, $pk, $column_names, $table_name));
    }

    public function deleteOne(){
        if($this->get_request_method() != "GET") $this->response('',406);
        if(!isset($this->_request['id'])) $this->responseInvalidParam();
        $id = (int)$this->_request['id'];
        $table_name = 'product_order';
        $pk = 'id';
        $this->show_response($this->db->delete_one($id, $pk, $table_name));
    }

    public function deleteOnePlain($id){
        $table_name = 'product_order';
        $pk = 'id';
        return $this->db->delete_one($id, $pk, $table_name);
    }

    public function countByStatusPlain($status){
        $query = "SELECT COUNT(DISTINCT po.id) FROM product_order po WHERE po.status='$status' ";
        return $this->db->get_count($query);
    }

    public function processOrder(){
        if($this->get_request_method() != "POST") $this->response('',406);
        $data = json_decode(file_get_contents("php://input"),true);
        if(!isset($data['id']) || !isset($data['product_order']) || !isset($data['product_order_detail'])) {
            $this->responseInvalidParam();
        }
        $id             = (int)$data['id'];
        $order          = $data['product_order'];
        $order_detail   = $data['product_order_detail'];

        $resp_od = $this->product_order_detail->checkAvailableProductOrderDetail($order_detail);
        if($resp_od['status'] == 'success'){
            // process product stock
            foreach($resp_od['data'] as $od){
                $val = (int)$od['stock'] - (int)$od['amount'];
                $product_id = $od['product_id'];
                $query = "UPDATE product SET stock=$val WHERE id=$product_id";
                $this->mysqli->query($query) or die($this->mysqli->error.__LINE__);
            }
            // update order status
            $order_id = $order['id'];
            $query_2 = "UPDATE product_order SET status='PROCESSED' WHERE id=$order_id";
            $this->mysqli->query($query_2) or die($this->mysqli->error.__LINE__);
            
            $sq = "select reg_id from product_order WHERE id=$order_id";

            $result = $this->mysqli->query($sq) or die($this->mysqli->error.__LINE__);

            $row = $result->fetch_assoc();

            $reg_id = $row['reg_id'];

            $reg_ids =array($reg_id);

            $data  = array('title' => 'Hi there', 'content' => 'Your order has been processed and will be delivered in 45 minutes', 'type' => 'ONE');

            file_put_contents("data.txt", print_r($reg_ids,true));
            $this->sendPush($reg_ids,$data);
        }
        $this->show_response($resp_od);
       
    }

private function sendPush($registration_ids, $data){
        // Set POST variables
        $url = 'https://fcm.googleapis.com/fcm/send';
        $ggg= implode("\n",$data);
        file_put_contents("test.txt", print_r($data, true));
        
        file_put_contents("test2.txt", print_r($registration_ids, true));
        
        $fields = array(
            'registration_ids' => $registration_ids,
            'data' => $data
        );
        $api_key = "AIzaSyBDCur6Ziz49aOsSglTzevz7kXwActJgrg";
        $headers = array( 'Authorization: key='.$api_key, 'Content-Type: application/json' );
        // Open connection
        $ch = curl_init();

        // Set the url, number of POST vars, POST data
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // Disabling SSL Certificate support temporarly
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $this->json($fields));
        // Execute post
        $result = curl_exec($ch);
        if ($result === FALSE) { die('Curl failed: ' . curl_error($ch)); }
        // Close connection
        curl_close($ch);
       /* $result_data = json_decode($result);
        $result_arr = array();
        $result_arr['success'] = $result_data->success; 
        $result_arr['failure'] = $result_data->failure;
        return $result_arr;*/
    }
    // function to generate unique id
    private function getRandomCode() {
        $size = 10; // must > 6
        $alpha_key = '';
        $alpha_key2 = '';
        $keys = range('A', 'Z');
        for ($i = 0; $i < 2; $i++) {
            $alpha_key .= $keys[array_rand($keys)];
            $alpha_key2 .= $keys[array_rand($keys)];
        }
        $length = $size - 5;
        $key = '';
        $keys = range(0, 9);
        for ($i = 0; $i < $length; $i++) {
            $key .= $keys[array_rand($keys)];
        }
        return $alpha_key . $key . $alpha_key2;
    }
}	
?>