from backend.character_identity import should_use_kurage_character, with_kurage_character


def test_character_identity_is_added_once():
    prompt = with_kurage_character("anime woman in a vertical market analysis studio")
    assert "short silver-white bob haircut" in prompt
    assert prompt.count("recurring original Kurage heroine") == 1
    assert with_kurage_character(prompt).count("recurring original Kurage heroine") == 1


def test_explicit_no_character_is_preserved():
    assert with_kurage_character("no character, empty landscape") == "no character, empty landscape"


def test_subject_only_scene_does_not_gain_a_character():
    prompt = "vertical market analysis studio with luminous charts"
    assert not should_use_kurage_character(prompt)
    assert with_kurage_character(prompt) == prompt


def test_presenter_scene_uses_canonical_character():
    assert should_use_kurage_character("news presenter explaining an AI chart")
    assert "silver-white bob" in with_kurage_character("news presenter explaining an AI chart")
