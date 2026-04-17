<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');

$currentTime = date('Y-m-d H:i:s');
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP Output</title>
    <style>
        body {
            font-family: "Malgun Gothic", sans-serif;
            background-color: #f3f3f3;
        }

        form {
            margin-bottom: 16px;
        }

        .name_field {
            margin-bottom: 10px;
        }

        .name_input_row {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 4px;
        }

        .paste_section {
            margin-bottom: 16px;
        }

        .paste_input {
            width: 100%;
            max-width: 420px;
            min-height: 120px;
            box-sizing: border-box;
            margin-top: 6px;
            padding: 8px;
            resize: vertical;
        }

        .paste_actions {
            margin-top: 8px;
        }

        .form_actions {
            display: flex;
            gap: 8px;
            margin-top: 12px;
        }

        .remove_button {
            flex: 0 0 auto;
        }

        .label_sheet {
            display: grid;
            grid-template-columns: repeat(3, 5.72cm);
            justify-content: start;
            column-gap: 0.32cm;
            row-gap: 0.32cm;
            align-content: flex-start;
        }

        .print_pad {
            width: 210mm;
            min-height: 297mm;
            padding: 10mm;
            box-sizing: border-box;
            background-color: #ffffff;
            margin: 0 auto;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
        }

        .name_block {
            width: 5.72cm;
            height: 4.45cm;
            border: 1px solid #000;
            box-sizing: border-box;
            position: relative;
            background-image: url('%EC%A1%B0%EB%81%BC%EB%AA%85%EC%B0%B0.gif');
            background-size: 100% 100%;
            background-repeat: no-repeat;
            overflow: hidden;
        }

        .name_value {
            position: absolute;
            top: 1.12cm;
            left: 1.62cm;
            right: 0.26cm;
            bottom: 0.42cm;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 0.2cm;
            font-size: 24px;
            font-weight: 700;
            letter-spacing: 0.16cm;
            color: #111111;
            line-height: 1.15;
            overflow-wrap: break-word;
            word-break: keep-all;
        }

        .empty_message {
            color: #666;
        }

        @page {
            size: A4;
            margin: 0;
        }

        @media print {
            .no_print {
                display: none;
            }

            .print_only {
                display: block;
            }

            body {
                margin: 0;
                background-color: #ffffff;
            }

            .print_pad {
                width: 210mm;
                min-height: 297mm;
                padding: 10mm;
                margin: 0;
                box-shadow: none;
            }

            .label_sheet {
                display: flex;
                flex-wrap: wrap;
                width: 17.16cm;
                justify-content: flex-start;
                column-gap: 0;
                row-gap: 0;
                align-content: flex-start;
            }

            .name_block {
                flex: 0 0 5.72cm;
            }
        }
    </style>
</head>
<body>
    <div class="no_print">
        <h1>형광조끼명찰</h1>
    </div>
    <form action="" class="no_print">
        <div class="paste_section">
            <label for="paste_names">붙여넣기 전용 입력창</label><br>
            <textarea
                id="paste_names"
                class="paste_input"
                placeholder="이름을 한 줄에 하나씩 붙여넣으세요"
            ></textarea>
            <div class="paste_actions">
                <button type="button" id="paste_apply_button">붙여넣기 반영</button>
            </div>
        </div>

        <div id="name_fields">
            <div class="name_field">
                <label for="name_1">이름 입력 1</label><br>
                <div class="name_input_row">
                    <input
                        type="text"
                        id="name_1"
                        name="name[]"
                        placeholder="이름을 입력하세요"
                    >
                    <button type="button" class="remove_button">삭제</button>
                </div>
            </div>
        </div>
        <div class="form_actions">
            <button type="button" id="add_button" class="no_print">추가</button>
            <button type="button" id="print_button" class="no_print">A4 출력</button>
            <button type="button" id="close_button" class="no_print">닫기</button>
        </div>
    </form>

    <div class="print_pad print_only">
        <div id="label_sheet" class="label_sheet">
            <p id="empty_message" class="empty_message">입력된 이름이 없습니다.</p>
        </div>
    </div>

    <script>
        const nameFields = document.getElementById('name_fields');
        const addButton = document.getElementById('add_button');
        const printButton = document.getElementById('print_button');
        const closeButton = document.getElementById('close_button');
        const pasteNames = document.getElementById('paste_names');
        const pasteApplyButton = document.getElementById('paste_apply_button');
        const labelSheet = document.getElementById('label_sheet');

        let fieldCount = 1;

        const renumberNameFields = () => {
            const fields = Array.from(nameFields.querySelectorAll('.name_field'));

            fields.forEach((field, index) => {
                const order = index + 1;
                const label = field.querySelector('label');
                const input = field.querySelector('input');

                label.setAttribute('for', `name_${order}`);
                label.textContent = `이름 입력 ${order}`;
                input.id = `name_${order}`;
            });

            fieldCount = fields.length;
        };

        const createRemoveButton = () => {
            const removeButton = document.createElement('button');
            removeButton.type = 'button';
            removeButton.className = 'remove_button';
            removeButton.textContent = '삭제';
            return removeButton;
        };

        const getParsedPasteNames = () => {
            return pasteNames.value
                .split(/\r?\n/)
                .map((value) => value.trim())
                .filter((value) => value !== '');
        };

        const renderNameBlocks = () => {
            const names = Array.from(nameFields.querySelectorAll('input'))
                .map((input) => input.value.trim())
                .filter((value) => value !== '');

            labelSheet.innerHTML = '';

            if (names.length === 0) {
                const emptyMessage = document.createElement('p');
                emptyMessage.id = 'empty_message';
                emptyMessage.className = 'empty_message';
                emptyMessage.textContent = '입력된 이름이 없습니다.';
                labelSheet.appendChild(emptyMessage);
                return;
            }

            names.forEach((name) => {
                const block = document.createElement('div');
                block.className = 'name_block';

                const value = document.createElement('div');
                value.className = 'name_value';
                value.textContent = name;

                block.appendChild(value);
                labelSheet.appendChild(block);
            });
        };

        const createNameField = () => {
            fieldCount += 1;

            const wrapper = document.createElement('div');
            wrapper.className = 'name_field';

            const label = document.createElement('label');
            label.setAttribute('for', `name_${fieldCount}`);
            label.textContent = `이름 입력 ${fieldCount}`;

            const inputRow = document.createElement('div');
            inputRow.className = 'name_input_row';

            const input = document.createElement('input');
            input.type = 'text';
            input.id = `name_${fieldCount}`;
            input.name = 'name[]';
            input.placeholder = '이름을 입력하세요';

            inputRow.appendChild(input);
            inputRow.appendChild(createRemoveButton());

            wrapper.appendChild(label);
            wrapper.appendChild(inputRow);
            nameFields.appendChild(wrapper);

            input.addEventListener('input', renderNameBlocks);
            return input;
        };

        const resetNameFields = () => {
            nameFields.innerHTML = '';
            fieldCount = 0;
        };

        const applyPastedNames = () => {
            const names = getParsedPasteNames();

            resetNameFields();

            if (names.length === 0) {
                const input = createNameField();
                input.focus();
                renderNameBlocks();
                return;
            }

            names.forEach((name, index) => {
                const input = createNameField();
                input.value = name;

                if (index === names.length - 1) {
                    input.focus();
                }
            });

            renumberNameFields();
            renderNameBlocks();
        };

        addButton.addEventListener('click', () => {
            createNameField();
        });

        pasteApplyButton.addEventListener('click', () => {
            applyPastedNames();
        });

        pasteNames.addEventListener('paste', () => {
            requestAnimationFrame(() => {
                applyPastedNames();
            });
        });

        nameFields.addEventListener('keydown', (event) => {
            if ((event.key !== 'Tab' && event.key !== 'Enter') || event.shiftKey) {
                return;
            }

            const inputs = nameFields.querySelectorAll('input');
            const lastInput = inputs[inputs.length - 1];
            const currentInput = event.target;

            if (currentInput !== lastInput) {
                return;
            }

            if (currentInput.value.trim() === '') {
                if (event.key === 'Enter') {
                    event.preventDefault();
                }
                return;
            }

            event.preventDefault();
            const newInput = createNameField();
            newInput.focus();
        });

        nameFields.addEventListener('click', (event) => {
            if (!event.target.classList.contains('remove_button')) {
                return;
            }

            const fields = nameFields.querySelectorAll('.name_field');
            const field = event.target.closest('.name_field');

            if (fields.length === 1) {
                const input = field.querySelector('input');
                input.value = '';
                input.focus();
                renderNameBlocks();
                return;
            }

            field.remove();
            renumberNameFields();
            renderNameBlocks();
        });

        printButton.addEventListener('click', () => {
            renderNameBlocks();
            window.print();
        });

        closeButton.addEventListener('click', () => {
            if (window.opener) {
                window.close();
                return;
            }

            if (window.history.length > 1) {
                window.history.back();
                return;
            }

            window.close();
        });

        nameFields.querySelector('input').addEventListener('input', renderNameBlocks);
    </script>
</body>
</html>