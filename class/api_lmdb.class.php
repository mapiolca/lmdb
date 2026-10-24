<?php
/* Copyright (C) 2026 Pierre Ardoin. SPDX-License-Identifier: GPL-3.0-or-later */
use Luracast\Restler\RestException;
require_once __DIR__.'/lmdbattestation.class.php';
/**
 * LMDB attestation REST API. Native token/bearer authentication only.
 * @access protected
 * @class DolibarrApiAccess {@requires user,external}
 */
class LmdbApi extends DolibarrApi
{
	public function __construct() { global $db; $this->db=$db; }

	/** List accessible attestations, newest first.
	 * @param int $limit Page size (1..100)
	 * @param int $page Zero-based page
	 * @return array
	 * @url GET /attestations
	 */
	public function attestations($limit=100,$page=0)
	{
		$actor=DolibarrApiAccess::$user;
		if (!isModEnabled('lmdb') || !$actor->hasRight('lmdb','lmdbattestation','api') || !$actor->hasRight('lmdb','lmdbattestation','read')) { throw new RestException(403); }
		$res=$this->db->query('SELECT t.rowid AS id,t.ref,t.entity,t.fk_soc,t.fk_version,t.date_attestation,t.date_acquisition,t.date_start,t.client_representative,t.license_number,t.status,t.date_validation FROM '.MAIN_DB_PREFIX.'lmdb_attestation t WHERE '.LmdbAttestation::scopeSql($this->db,$actor).' ORDER BY t.rowid DESC'.$this->db->plimit(max(1,min(100,(int)$limit)),max(0,(int)$page)*max(1,min(100,(int)$limit))));
		if (!$res) { throw new RestException(503,'Storage unavailable'); }
		$result=array();
		while (is_object($row=$this->db->fetch_object($res))) {
			foreach (array('id','entity','fk_soc','fk_version','status') as $key) { $row->{$key}=(int)$row->{$key}; }
			foreach (array('date_attestation','date_acquisition','date_start','date_validation') as $key) { $row->{$key}=$this->db->jdate($row->{$key}); }
			$result[]=(array)$row;
		}
		return $result;
	}

	/** @param int $id Attestation ID
	 * @return array
	 * @url GET /attestations/{id}
	 */
	public function getAttestation($id)
	{
		$actor=DolibarrApiAccess::$user;
		if (!isModEnabled('lmdb') || !$actor->hasRight('lmdb','lmdbattestation','api') || !$actor->hasRight('lmdb','lmdbattestation','read')) { throw new RestException(403); }
		$object=new LmdbAttestation($this->db);
		if ($object->fetch((int)$id)<=0) { throw new RestException(404); }
		$result=array();
		foreach (array('id','ref','entity','fk_soc','fk_version','date_attestation','date_acquisition','date_start','client_representative','license_number','status','date_validation') as $key) { $result[$key]=$object->{$key}; }
		return $result;
	}

	/** Create a draft. Dates are Unix timestamps; optional dates may be null.
	 * @param array $request_data Writable attestation fields
	 * @return int Created ID
	 * @url POST /attestations
	 */
	public function createAttestation($request_data=null)
	{
		$actor=DolibarrApiAccess::$user;
		if (!isModEnabled('lmdb') || !$actor->hasRight('lmdb','lmdbattestation','api') || !$actor->hasRight('lmdb','lmdbattestation','read') || !$actor->hasRight('lmdb','lmdbattestation','write')) { throw new RestException(403); }
		$object=new LmdbAttestation($this->db);
		$this->assignInputs($object,$request_data);
		if ($object->create($actor)<=0) { throw new RestException(400,'Invalid attestation'); }
		return (int)$object->id;
	}

	/** @param int $id Draft ID
	 * @param array $request_data Writable attestation fields
	 * @return array
	 * @url PUT /attestations/{id}
	 */
	public function updateAttestation($id,$request_data=null)
	{
		$actor=DolibarrApiAccess::$user;
		if (!isModEnabled('lmdb') || !$actor->hasRight('lmdb','lmdbattestation','api') || !$actor->hasRight('lmdb','lmdbattestation','read') || !$actor->hasRight('lmdb','lmdbattestation','write')) { throw new RestException(403); }
		$object=new LmdbAttestation($this->db);
		if ($object->fetch((int)$id)<=0) { throw new RestException(404); }
		$this->assignInputs($object,$request_data);
		if ($object->update($actor)<=0) { throw new RestException(400,'Invalid or locked attestation'); }
		return $this->getAttestation($id);
	}

	/** @param int $id Attestation ID
	 * @return array
	 * @url POST /attestations/{id}/validate
	 */
	public function validateAttestation($id)
	{
		$actor=DolibarrApiAccess::$user;
		if (!isModEnabled('lmdb') || !$actor->hasRight('lmdb','lmdbattestation','api') || !$actor->hasRight('lmdb','lmdbattestation','read') || !$actor->hasRight('lmdb','lmdbattestation','validate')) { throw new RestException(403); }
		$object=new LmdbAttestation($this->db);
		if ($object->fetch((int)$id)<=0) { throw new RestException(404); }
		if ($object->validate($actor)<=0) { throw new RestException(400,'Validation unavailable'); }
		return $this->getAttestation($id);
	}

	/** @param int $id Attestation ID
	 * @return array
	 * @url POST /attestations/{id}/cancel
	 */
	public function cancelAttestation($id)
	{
		$actor=DolibarrApiAccess::$user;
		if (!isModEnabled('lmdb') || !$actor->hasRight('lmdb','lmdbattestation','api') || !$actor->hasRight('lmdb','lmdbattestation','read') || !$actor->hasRight('lmdb','lmdbattestation','cancel')) { throw new RestException(403); }
		$object=new LmdbAttestation($this->db);
		if ($object->fetch((int)$id)<=0) { throw new RestException(404); }
		if ($object->cancel($actor)<=0) { throw new RestException(400,'Cancellation unavailable'); }
		return $this->getAttestation($id);
	}

	/** @param int $id Draft ID
	 * @return bool
	 * @url DELETE /attestations/{id}
	 */
	public function deleteAttestation($id)
	{
		$actor=DolibarrApiAccess::$user;
		if (!isModEnabled('lmdb') || !$actor->hasRight('lmdb','lmdbattestation','api') || !$actor->hasRight('lmdb','lmdbattestation','read') || !$actor->hasRight('lmdb','lmdbattestation','delete')) { throw new RestException(403); }
		$object=new LmdbAttestation($this->db);
		if ($object->fetch((int)$id)<=0) { throw new RestException(404); }
		if ($object->delete($actor)<=0) { throw new RestException(400,'Deletion unavailable'); }
		return true;
	}

	/** Generate the frozen French PDF; download through the native Documents API.
	 * @param int $id Validated attestation ID
	 * @return bool
	 * @url POST /attestations/{id}/document
	 */
	public function documentAttestation($id)
	{
		global $langs;
		$actor=DolibarrApiAccess::$user;
		if (!isModEnabled('lmdb') || !$actor->hasRight('lmdb','lmdbattestation','api') || !$actor->hasRight('lmdb','lmdbattestation','read') || !$actor->hasRight('lmdb','lmdbattestation','generate')) { throw new RestException(403); }
		$object=new LmdbAttestation($this->db);
		if ($object->fetch((int)$id)<=0) { throw new RestException(404); }
		if ($object->generateDocument('loifinance',$langs)<=0) { throw new RestException(400,'Generation unavailable'); }
		return true;
	}

	/** Strict API boundary; no status, entity, snapshot or path can be assigned.
	 * @param LmdbAttestation $object Target
	 * @param array<string,scalar|null>|null $data Inputs
	 * @return void
	 */
	private function assignInputs($object,$data)
	{
		if (!is_array($data)) { throw new RestException(400,'Expected an object'); }
		$integers=array('fk_soc','fk_version','date_attestation','date_acquisition','date_start');
		$strings=array('client_representative','license_number');
		foreach ($data as $key=>$value) {
			if (in_array($key,$integers,true) && (is_int($value) || $value===null) && ($value===null || $value>=0)) { $object->{$key}=$value; }
			elseif (in_array($key,$strings,true) && is_string($value) && $value===dol_string_nohtmltag($value)) { $object->{$key}=$value; }
			else { throw new RestException(400,'Unknown or invalid field'); }
		}
	}
}
