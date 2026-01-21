#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Script para analizar la base de datos SQL de KINO
y detectar problemas con códigos asignados a PDFs
"""

import re
import sqlite3
from collections import defaultdict, Counter
from pathlib import Path

def parse_sql_file(sql_file):
    """Parse el archivo SQL y extrae datos de documentos y códigos"""
    
    with open(sql_file, 'r', encoding='utf-8') as f:
        content = f.read()
    
    # Extraer documentos
    docs_pattern = r"INSERT INTO `documents` \(`id`, `name`, `date`, `path`, `codigos_extraidos`\) VALUES\s*((?:\([^;]+\)[,;]?\s*)+)"
    docs_match = re.search(docs_pattern, content, re.MULTILINE | re.DOTALL)
    
    documents = {}
    if docs_match:
        docs_data = docs_match.group(1)
        # Parsear cada línea de documento
        doc_pattern = r"\((\d+),\s*'([^']+)',\s*'([^']+)',\s*'([^']+)',\s*(NULL|'[^']*')\)"
        for match in re.finditer(doc_pattern, docs_data):
            doc_id = int(match.group(1))
            documents[doc_id] = {
                'id': doc_id,
                'name': match.group(2),
                'date': match.group(3),
                'path': match.group(4),
                'codigos_extraidos': match.group(5)
            }
    
    # Extraer códigos
    codes_pattern = r"INSERT INTO `codes` \(`id`, `document_id`, `code`\) VALUES\s*((?:\([^;]+\)[,;]?\s*)+)"
    codes_matches = re.findall(codes_pattern, content, re.MULTILINE | re.DOTALL)
    
    codes = []
    for codes_chunk in codes_matches:
        code_pattern = r"\((\d+),\s*(\d+),\s*'([^']+)'\)"
        for match in re.finditer(code_pattern, codes_chunk):
            codes.append({
                'id': int(match.group(1)),
                'document_id': int(match.group(2)),
                'code': match.group(3)
            })
    
    return documents, codes

def analyze_database(documents, codes):
    """Analiza la base de datos y genera reportes de problemas"""
    
    print("="*80)
    print("🔍 ANÁLISIS DE BASE DE DATOS KINO")
    print("="*80)
    print()
    
    # Estadísticas generales
    print(f"📊 ESTADÍSTICAS GENERALES:")
    print(f"   Total de documentos: {len(documents)}")
    print(f"   Total de códigos: {len(codes)}")
    print()
    
    # Agrupar códigos por documento
    codes_by_doc = defaultdict(list)
    for code in codes:
        codes_by_doc[code['document_id']].append(code['code'])
    
    # Análisis 1: Documentos sin códigos
    print("⚠️  DOCUMENTOS SIN CÓDIGOS ASIGNADOS:")
    docs_without_codes = []
    for doc_id, doc in documents.items():
        if doc_id not in codes_by_doc or len(codes_by_doc[doc_id]) == 0:
            docs_without_codes.append(doc)
            print(f"   ID {doc_id}: {doc['name']} ({doc['path']})")
    
    if not docs_without_codes:
        print("   ✅ Todos los documentos tienen códigos asignados")
    print()
    
    # Análisis 2: Códigos duplicados
    print("🔄 CÓDIGOS DUPLICADOS (mismo código en múltiples documentos):")
    all_codes_with_docs = [(code['code'], code['document_id']) for code in codes]
    code_occurrences = defaultdict(list)
    for code, doc_id in all_codes_with_docs:
        code_occurrences[code].append(doc_id)
    
    duplicates = {code: docs for code, docs in code_occurrences.items() if len(docs) > 1}
    
    if duplicates:
        duplicate_count = 0
        for code, doc_ids in sorted(duplicates.items()):
            if duplicate_count < 20:  # Mostrar solo los primeros 20
                doc_names = [documents[did]['name'] for did in doc_ids if did in documents]
                print(f"   Código '{code}' aparece en {len(doc_ids)} documentos:")
                for doc_id in doc_ids:
                    if doc_id in documents:
                        print(f"      - Doc ID {doc_id}: {documents[doc_id]['name']}")
                print()
                duplicate_count += 1
        
        if len(duplicates) > 20:
            print(f"   ... y {len(duplicates) - 20} códigos duplicados más")
        
        print(f"   📈 RESUMEN: {len(duplicates)} códigos únicos están duplicados")
    else:
        print("   ✅ No hay códigos duplicados")
    print()
    
    # Análisis 3: Documentos con más códigos
    print("📑 TOP 10 DOCUMENTOS CON MÁS CÓDIGOS:")
    docs_with_counts = [(doc_id, len(codes_by_doc[doc_id])) for doc_id in codes_by_doc]
    docs_with_counts.sort(key=lambda x: x[1], reverse=True)
    
    for i, (doc_id, count) in enumerate(docs_with_counts[:10], 1):
        if doc_id in documents:
            print(f"   {i}. {documents[doc_id]['name']}: {count} códigos")
    print()
    
    # Análisis 4: Códigos con patrones extraños
    print("🚨 CÓDIGOS CON PATRONES POTENCIALMENTE PROBLEMÁTICOS:")
    suspicious_codes = []
    
    for code_entry in codes:
        code = code_entry['code']
        # Detectar códigos muy cortos o muy largos
        if len(code) < 2:
            suspicious_codes.append((code, code_entry['document_id'], "Código muy corto"))
        elif len(code) > 30:
            suspicious_codes.append((code, code_entry['document_id'], "Código muy largo"))
        # Detectar códigos con caracteres extraños
        elif not re.match(r'^[A-Za-z0-9:\-+/.()]+$', code):
            suspicious_codes.append((code, code_entry['document_id'], "Caracteres especiales"))
    
    if suspicious_codes:
        for i, (code, doc_id, reason) in enumerate(suspicious_codes[:15], 1):
            if doc_id in documents:
                print(f"   {i}. '{code}' en Doc ID {doc_id} ({documents[doc_id]['name']}) - {reason}")
        if len(suspicious_codes) > 15:
            print(f"   ... y {len(suspicious_codes) - 15} códigos sospechosos más")
    else:
        print("   ✅ No se detectaron códigos con patrones problemáticos")
    print()
    
    # Análisis 5: IDs de documentos faltantes en tabla codes
    print("🔍 VERIFICACIÓN DE INTEGRIDAD REFERENCIAL:")
    doc_ids_referenced = set(code['document_id'] for code in codes)
    missing_docs = doc_ids_referenced - set(documents.keys())
    
    if missing_docs:
        print(f"   ⚠️  Códigos hacen referencia a {len(missing_docs)} documentos que NO EXISTEN:")
        for doc_id in sorted(missing_docs)[:10]:
            codes_for_missing = [c['code'] for c in codes if c['document_id'] == doc_id]
            print(f"      Doc ID {doc_id}: {len(codes_for_missing)} códigos huérfanos")
            print(f"         Ejemplos: {', '.join(codes_for_missing[:5])}")
    else:
        print("   ✅ Todos los códigos referencian documentos existentes")
    print()
    
    # Retornar datos para análisis adicional
    return {
        'duplicates': duplicates,
        'docs_without_codes': docs_without_codes,
        'codes_by_doc': dict(codes_by_doc),
        'suspicious_codes': suspicious_codes,
        'missing_doc_refs': missing_docs
    }

if __name__ == '__main__':
    sql_file = r'c:\Users\Usuario\Desktop\kino-trace\if0_39064130_buscador (10).sql'
    
    print("Cargando y parseando archivo SQL...")
    documents, codes = parse_sql_file(sql_file)
    
    print(f"✅ Cargados {len(documents)} documentos y {len(codes)} códigos")
    print()
    
    analysis = analyze_database(documents, codes)
    
    print("="*80)
    print("✅ ANÁLISIS COMPLETADO")
    print("="*80)
