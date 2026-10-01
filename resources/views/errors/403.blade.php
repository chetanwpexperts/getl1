@extends('errors.layout')

@section('code', 'Error 403')
@section('title', 'Access denied')
@section('message')
{{ $exception->getMessage() ?: "You don't have permission to open this page. If you think you should, ask your company admin." }}
@endsection
