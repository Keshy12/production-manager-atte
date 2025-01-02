<?php
use Atte\DB\MsaDB;
use Atte\Utils\MagazineRepository;

?>

<div id="commissionResult" class="d-flex flex-column justify-content-center align-items-center">
    <div class="d-flex justify-content-center align-items-center">
        <div id="commissionResultTableContainer">
            <h4 class="text-center my-4">
                Tworzone zlecenia
            </h4>
            <table id="commissionTable" class="table table-bordered table-sm table-hover text-center w-50">
                <thead>
                    <th>Odbiorca</th>
                    <th>Urządzenie</th>
                    <th>Laminat</th>
                    <th>Wersja</t>
                    <th>Ilość</th>
                </thead>
                <tbody id="commissionResultTBody"></tbody>
            </table>
        </div>
    </div>
    <div id="componentListResult" class="mt-4">
        <table class="table table-striped">
            <thead>
                <tr class="text-center">
                    <th scope="col">Komponent</th>
                    <th scope="col">Ilość przekazywana</th>
                </tr>
            </thead>
            <tbody class="text-center" id="componentstableResult">
            </tbody>
        </table>
    </div>
</div>