<?php 

$CURRENT_MODULE = "DATABASE REWORK";
append_log("Loading databases");

$_SESSION['DATABASE']['C_CONNECTOR'] = 	function($username, $password){
											$object = new SQL_CONNECTER($username, $password);
											return $object;
										};
$_SESSION['DATABASE']['C_DATABASE'] = 	function($connector, $name){
											return new DATABASE($connector, $name);
										};							
$_SESSION['DATABASE']['C_TABLE']	=	function($database, $name){
											return new TABLE($database, $name);
										};

class SQL_CONNECTER{
	
	private $LINK;
	
	function __construct($uname, $pword, $host='localhost'){
		append_log("attempting loggin in with: \nUSERNAME:\t".$uname."\nPASSWORD:\t".$pword."\nHOST:\t".$host."\n");
		$this->LINK = mysqli_connect($host, $uname,$pword);
		append_log("Connection established");
	}
	
	// sends a query to the server and returns the responce
	function talk($QUERY){
		append_log("Sending query: ". $QUERY);
		$responce = mysqli_query($this->LINK, $QUERY);
		if(mysqli_error($this->LINK))
			append_log(mysqli_error($this->LINK));
		return $responce;
	}
	
	// returns an array of all databases
	function get_databases(){
		$query = "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA";
		$ret = array();
		while(($e = mysqli_fetch_array($ret))!= null)
			array_push($ret, $e);
		
		return $ret;
	}
	
	
	// selects a spesific database
	function select_database($name){
		mysqli_select_db($this->LINK, $name);
	}
	
}

class DATABASE{
	public $NAME;
	private $CONNECTOR, $TABELS = array();
	
	function __construct($connector, $name){
		$this->NAME = $name;
		$this->CONNECTOR = $connector;
		if(!$this->check_exist())
			$this->create();
		$this->populate_tables();
	}
	
	// checks if database exists
	private function check_exist(){
		$query = "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '".$this->NAME."'";
		$responce = $this->CONNECTOR->talk($query);
		return !empty(mysqli_fetch_array($responce));
	}
	
	// creates a database
	function create(){
		$query = "CREATE DATABASE IF NOT EXISTS ".$this->NAME;
		if($this->CONNECTOR->talk($query))
			append_log("Database created: ".$this->NAME);
		
	}
	
	function populate_tables(){
		$query = "SHOW TABLES";
		$res = $this->relay($query);
		while($q = mysqli_fetch_array($res)){
			array_push($this->TABELS, new TABLE($this, $q[0]));
		}
	}
	
	// drops a database
	function drop(){
		$query = "DROP DATABASE ".$this->NAME;
		if($this->CONNECTOR->talk($query))
			append_log("Database has been dropped: ".$this->NAME);
	}
	
	//gets the tables in the database
	function get_tables(){
		$query = "SHOW TABLES";
		$res = $this->relay($query);
		if($res)
			while( ($e = mysqli_fetch_array($res)))
				print_r($e);
		else return false;
	}
	
	// relays a query to the connector after selecting the database
	function relay($query){
		$this->CONNECTOR->select_database($this->NAME);
		return $this->CONNECTOR->talk($query);
	}
}

class TABLE{
	
	private $DATABASE, $NAME, $COLUMNS = array();
	
	function __construct($database, $name){
		$this->DATABASE = $database;
		$this->NAME = $name;
		$this->COLUMNS  = array("ID"=>new COLUMN("ID", "INT", null, null, null, "AUTO_INCREMENT"));
		if(!$this->exists()){
			$this->create();
		}
		$this->read_columns();
	}
	
	function get_columns(){
		return $this->COLUMNS;
	}
	
	// Tests if tabel exists
	function exists(){
		$query = "SHOW TABLES LIKE '".$this->NAME."'";
		$res = $this->DATABASE->relay($query);
		return $res->num_rows;
	}
	
	// creates database
	function create(){
		$query = "CREATE TABLE ".$this->NAME." (".$this->compile_columns().") ";
		$this->DATABASE->relay($query);
	}
	
	// drops the table
	function drop(){
		$query = "DROP TABLE ".$this->TABLE;
		$this->DATABASE->relay($query);
	}
	
	// empties the table
	function truncate(){
		$query = "TRUNCATE ".$this->TABLE;
		$this->DATABASE->relay($query);
	}
	
	// Generates columns for the table
	private function read_columns(){
		$query = "SELECT * FROM `INFORMATION_SCHEMA`.`COLUMNS` WHERE `TABLE_SCHEMA`='".$this->DATABASE->NAME."' AND `TABLE_NAME`='".$this->NAME."'";
		$rep = $this->DATABASE->relay($query);
		$this->COLUMNS = array();
		while(($e = mysqli_fetch_array($rep))){
			$name = $e['COLUMN_NAME'];
			$default = $e['COLUMN_DEFAULT'];
			$null = $e["IS_NULLABLE"] != "NO";
			$type = $e['DATA_TYPE'];
			$size = $e['CHARACTER_MAXIMUM_LENGTH'];
			$c = new COLUMN($name, $type, $size, $default, $null);
			append_log("created column: ".$c);
			$this->COLUMNS[$c->NAME] = $c;
		}
	}
	
	// compiles column string
	private function compile_columns(){
		$ret = implode(', ', $this->COLUMNS);
		$ret .= ", PRIMARY KEY (ID)";
		return $ret;
	}
	
	//adds a new column
	function add_column($clm){
		$query = "ALTER TABLE ".$this->NAME." ADD ".$clm;
		if($this->DATABASE->relay($query))
			array_push($this->COLUMNS, $clm);
	}
	
	// fetch the rows matching the provided columns
	function fetch($COLUMNS=null){
		
		$select = "*";
		$where = "";
		
		if($COLUMNS and $COLUMNS!="*"){
			$select = array();
			$where = array();
			foreach($COLUMNS as $c){
				array_push($select, $c->NAME);
				if($c->VALUE){
					array_push($where, $c->NAME."='".$c->VALUE."'");
				}
			}
			$select = implode(", ", $select);
			if(!empty($where)){
				$where = "WHERE ".implode(", ", $where);
			}else{
				$where = "";
			}
		}
		
		$query = "SELECT ".$select." FROM ".$this->NAME." ".$where;
		return $this->DATABASE->relay($query);
	}
	
	// converts the fetched query into useable data
	function normalize($request){
		
		if($request->num_rows<=0){
			return 0;
		}
		
		if($request->num_rows==1){
			$hold = mysqli_fetch_assoc($request);
			$ret = array();
			foreach($hold as $k=>$c){
				$ret[$k] = $this->COLUMNS[$k]->duplicate();
				$ret[$k]->VALUE = $c;
			}
			return $ret;
		}
		
		$rows = array();
		while($hold = $mysqli_fetch_assoc($request)){
			$hold = mysqli_fetch_assoc($request);
			$cols = array();
			foreach($hold as $k=>$c){
				$ret[$k] = $this->COLUMNS[$k]->duplicate();
				$ret[$k]->VALUE = $c;
			}
			array_push($rows, $ret);
		}
		return $rows;
	}
	
	// inserts a new row into the database
	function insert($columns){
		$names = array(); 
		$values = array();
		
		foreach($columns as $c){
			array_push($names, $c->NAME);
			array_push($values, $c->VALUE);
		}
		
		$names = implode(", ", $names);
		$values = "'".implode("', '", $values)."'";
		
		$query = "INSERT INTO ".$this->NAME." (".$names.") VALUES (".$values.")";
		$this->DATABASE->relay($query);
	}
	
	// Updates cells depending on the provided referances
	function update($alterations, $refrence = null){
		
		$alter = array();
		foreach($alterations as $c){
			array_push($alter, $c->NAME."='".$c->VALUE."'");
		}
		
		$ref = "";
		if($refrence){
			$ref = " WHERE ".$refrence->NAME."=".$refrence->VALUE;
		}
		
		$query = "UPDATE ".$this->NAME." SET ".implode(', ',$alter)." ".$ref;
		return $this->DATABASE->relay($query);
	}
}

class COLUMN{
	
	private $TYPE, $SIZE, $DEFAULT, $NULL, $META;
	public $VALUE, $NAME;
	
	
	function __construct($name, $type, $size=null, $default=null, $NULL=false, $Meta=""){
		$this->NAME = $name;
		$this->TYPE = $type;
		$this->SIZE = $size;
		$this->META = $Meta;
		$this->DEFAULT = $default;
	}
	
	function __toString(){
		$ret = "".$this->NAME." ".$this->TYPE;
		if($this->SIZE) $ret .= "(".$this->SIZE.")";
		if($this->NULL) $ret .= " NULL";
		else			$ret .= " NOT NULL";
		if($this->DEFAULT) $ret .= " DEFAULT '".$this->DEFAULT."'";
		$ret .= " ".$this->META;
		return $ret;
	}
	
	function duplicate(){
		$ret = new COLUMN($this->NAME, $this->TYPE, $this->SIZE, $this->DEFAULT, $this->NULL);
		$ret->VALUE = $this->VALUE;
		return $ret;
	}
}





?>